<?php

namespace Flute\Core\Template;

use Exception;
use Flute\Core\Cache\SWRQueue;
use Flute\Core\Theme\ThemeManager;
use MatthiasMullie\Minify;
use Nette\Utils\Validators;
use Padaliyajay\PHPAutoprefixer\Autoprefixer;
use ScssPhp\ScssPhp\Exception\CompilerException;
use ScssPhp\ScssPhp\Exception\SassException;
use ScssPhp\ScssPhp\OutputStyle;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use WebPConvert\WebPConvert;

class TemplateAssets
{
    private const CSS_CACHE_DIR = 'assets/css/cache/';

    private const JS_CACHE_DIR = 'assets/js/cache/';

    private const IMG_CACHE_DIR = 'assets/img/cache/';

    private const SUPPORTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /**
     * Cache mapping for extension to asset type tag generation.
     */
    private const EXTENSION_TO_TYPE = [
        'css' => 'css',
        'scss' => 'css',
        'js' => 'js',
        'mjs' => 'js',
        'jpg' => 'img',
        'jpeg' => 'img',
        'png' => 'img',
        'gif' => 'img',
        'webp' => 'img',
        'svg' => 'img',
    ];

    protected TemplateScssCompiler $scssCompiler;

    protected Template $template;

    protected string $context = 'main';

    protected bool $minifyAssets;

    protected bool $autoprefixAssets;

    protected bool $debugMode;

    protected string $appUrl;

    protected int $remoteAssetTimeout = 5;

    /**
     * Maximum remote asset size allowed for CDN caching.
     */
    protected int $remoteAssetMaxBytes = 2_097_152; // 2 MB

    /**
     * Optional host allowlist for remote assets.
     *
     * @var array<int, string>
     */
    protected array $allowedRemoteHosts = [];

    /**
     * Safety threshold to skip autoprefixing very large stylesheets (bytes).
     */
    protected int $autoprefixMaxBytes = 400000; // ~400 KB; configurable via assets.autoprefix_max_bytes

    protected array $additionalScssFiles = [
        'main' => [],
        'admin' => [],
    ];

    protected array $additionalPartials = [
        'app/Core/Template/Resources/sass/_mixins.scss',
        'app/Core/Template/Resources/sass/_helpers.scss',
    ];

    protected array $assetPathCache = [];

    protected array $compilationCache = [];

    protected array $fallbackAssetPaths = [];

    protected string $standardTheme = 'standard';

    /**
     * Request-scope filesystem metadata cache to reduce repeated IO.
     *
     * @var array<string, bool>
     */
    protected array $fileExistsCache = [];

    /**
     * @var array<string, int>
     */
    protected array $fileMtimeCache = [];

    /**
     * Cache shared partials content by fingerprint of partial mtimes.
     *
     * @var array<string, string>
     */
    protected array $sharedPartialsCache = [];

    /**
     * Accumulated time spent on compiling/minifying theme assets (scss, js, etc.)
     */
    protected static float $assetsCompileTime = 0.0;

    public function __construct()
    {
        $this->minifyAssets = config('assets.minify');
        $this->autoprefixAssets = (bool) config('assets.autoprefix', false);
        $this->debugMode = false;
        $this->appUrl = config('app.url');
        $timeout = (int) (config('assets.remote_asset_timeout') ?? 5);
        $this->remoteAssetTimeout = $timeout > 0 ? $timeout : 5;
        $maxBytes = (int) (config('assets.remote_asset_max_bytes') ?? 0);
        if ($maxBytes > 0) {
            $this->remoteAssetMaxBytes = $maxBytes;
        }
        $allowedHosts = config('assets.allowed_remote_hosts', []);
        if (is_array($allowedHosts)) {
            $this->allowedRemoteHosts = array_values(
                array_filter(array_map(static fn ($host) => strtolower(trim((string) $host)), $allowedHosts))
            );
        }
        $limit = (int) (config('assets.autoprefix_max_bytes') ?? 0);

        if ($limit > 0) {
            $this->autoprefixMaxBytes = $limit;
        }

        if (is_development()) {
            $this->debugMode = true;
        }

        $this->scssCompiler = new TemplateScssCompiler();
        $this->scssCompiler->setOutputStyle($this->minifyAssets ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);

        $this->scssCompiler->addImportPath(path('app'));
    }

    /**
     * Initializes the template and context for asset handling, adding a custom directive
     * for embedding assets into the template.
     *
     * @param Template $template The template object to associate with this instance.
     * @param string $context The context in which assets are loaded (e.g., 'main' or 'admin').
     */
    public function init(Template $template, string $context = 'main'): void
    {
        $this->template = $template;
        $this->context = $context;

        $this->template->addDirective("at", static function ($expression) {
            if (strpos($expression, ',') !== false) {
                return "<?php echo app('Flute\\Core\\Template\\TemplateAssets')->assetFunction({$expression}); ?>";
            }

            return "<?php echo app('Flute\\Core\\Template\\TemplateAssets')->assetFunction({$expression}, false); ?>";
        });

        $this->loadThemeScssAppends();
    }

    /**
     * Generates the appropriate URL or HTML tag for an asset based on its type (CSS, JS, image).
     *
     * @param string $expression The path or URL of the asset.
     * @param bool $urlOnly Whether to return only the URL instead of the full HTML tag.
     * @return string The generated HTML tag or asset URL.
     */
    public function assetFunction(string $expression, bool $urlOnly = false): string
    {
        $expression = $this->applyAssetReplacement($expression);
        if ($this->containsPathTraversal($expression)) {
            logs('security')->warning('Blocked asset expression with path traversal', ['expression' => $expression]);

            return '';
        }

        $filePath = $this->resolveFilePath($expression);
        if ($filePath === '') {
            return '';
        }
        $extension = $this->getFileExtension($expression, $filePath);
        $pathParts = explode("/", $expression);
        $firstSegment = $pathParts[0] ?? '';

        if ($firstSegment === "assets") {
            $url = $this->generateAssetUrl($expression);

            return $urlOnly ? $this->extractUrl($url) : $url;
        }

        return $this->processAssetBasedOnExtension($extension, $expression, $filePath, $urlOnly);
    }

    /**
     * Adds a SCSS file to the list for a specified context if it exists and is valid.
     *
     * @param string $path The path to the SCSS file.
     * @param string $context The context ('main' or 'admin') for which this file should be added.
     */
    public function addScssFile(string $path, string $context): void
    {
        if ($this->fileExists($path) && pathinfo($path, PATHINFO_EXTENSION) === 'scss') {
            $this->additionalScssFiles[$context][] = $path;
        } else {
            logs()->warning("SCSS file not found or invalid: {$path}");
        }
    }

    /**
     * Retrieves the SCSS compiler instance for compiling SCSS content.
     *
     * @return TemplateScssCompiler The SCSS compiler instance.
     */
    public function getCompiler(): TemplateScssCompiler
    {
        return $this->scssCompiler;
    }

    /**
     * Get the accumulated time spent on compiling/minifying theme assets (scss, js, etc.)
     */
    public static function getAssetsCompileTime(): float
    {
        return self::$assetsCompileTime;
    }

    /**
     * Add import path with context support.
     */
    public function addImportPath(string $path, string $context = 'main'): void
    {
        if ($context === $this->context && is_dir($path)) {
            $this->scssCompiler->addImportPath($path);
        }
    }

    /**
     * Clear all caches.
     */
    public function clearCache(): void
    {
        $this->assetPathCache = [];
        $this->compilationCache = [];
        $this->fallbackAssetPaths = [];
        $this->fileExistsCache = [];
        $this->fileMtimeCache = [];
        $this->sharedPartialsCache = [];
    }

    /**
     * Clear style cache files and internal caches.
     */
    public function clearStyleCache(): void
    {
        $cssCachePath = BASE_PATH . '/public/assets/css/cache/*';
        $filesystem = new Filesystem();
        $filesystem->remove(glob($cssCachePath));
        $this->clearCache();
    }

    /**
     * Get cache statistics.
     */
    public function getCacheStats(): array
    {
        return [
            'asset_path_cache_size' => count($this->assetPathCache),
            'compilation_cache_size' => count($this->compilationCache),
            'debug_mode' => $this->debugMode,
            'context' => $this->context,
        ];
    }

    /**
     * Find asset file with fallback support across themes.
     *
     * @param string $relativePath Relative path from theme directory
     * @param string $type Asset type (scripts, images, sass, etc.)
     * @return string|null Found file path or null
     */
    protected function findAssetWithFallback(string $relativePath, string $type = 'scripts'): ?string
    {
        $cacheKey = "asset:{$type}:{$relativePath}";

        if (isset($this->assetPathCache[$cacheKey])) {
            return $this->assetPathCache[$cacheKey];
        }

        $themes = $this->getThemeFallbackOrder();

        foreach ($themes as $theme) {
            $assetPath = BASE_PATH . "app/Themes/{$theme}/assets/{$type}/{$relativePath}";
            if ($this->fileExists($assetPath)) {
                return $this->assetPathCache[$cacheKey] = $assetPath;
            }
        }

        return $this->assetPathCache[$cacheKey] = null;
    }

    /**
     * Get theme fallback order.
     */
    protected function getThemeFallbackOrder(): array
    {
        $currentTheme = app(ThemeManager::class)->getCurrentTheme() ?? $this->standardTheme;
        $themes = [$currentTheme];

        if ($currentTheme !== $this->standardTheme) {
            $themes[] = $this->standardTheme;
        }

        return $themes;
    }

    /**
     * Returns the cache directory path for a given asset type (e.g., CSS, JS).
     *
     * @param string $type The asset type ('css', 'js', etc.).
     * @return string The cache directory path.
     */
    protected function getCacheDir(string $type): string
    {
        return "assets/{$type}/cache/{$this->context}/";
    }

    protected function getStaleCacheDir(string $type): string
    {
        return "assets/{$type}/cache_stale/{$this->context}/";
    }

    /**
     * Gather SCSS contents with fallback support.
     */
    protected function gatherScssContents(string $mainScssPath): array
    {
        $scssContents = [];

        // Load shared partials first
        $partialsContent = $this->loadSharedPartials();
        $scssContents[] = $partialsContent;

        // Main SCSS content
        $mainScssContent = $this->readFile($mainScssPath);
        if ($mainScssContent === false) {
            logs()->error("Unable to read SCSS file: {$mainScssPath}");

            return [];
        }
        $scssContents[] = $mainScssContent;

        // Additional SCSS files for context
        foreach ($this->additionalScssFiles[$this->context] as $additionalFile) {
            if ($this->fileExists($additionalFile)) {
                $additionalContent = $this->readFile($additionalFile);
                if ($additionalContent !== false) {
                    $scssContents[] = $additionalContent;
                } else {
                    logs()->warning("Unable to read additional SCSS file: {$additionalFile}");
                }
            }
        }

        return $scssContents;
    }

    /**
     * Processes an external asset URL (CSS, JS, or image), caching it locally if it's not already present.
     *
     * @param string $url The URL of the external asset.
     * @param string $type The asset type ('css', 'js', or 'img').
     * @return string The generated HTML tag or local asset URL.
     */
    protected function processRemoteAsset(string $url, string $type): string
    {
        if ($this->isLocalUrl($url)) {
            return $this->generateTag($url, $type);
        }

        $localUrl = $this->processCdnAsset($url, $type);

        if ($localUrl === '') {
            $safeUrl = $this->sanitizeRemoteUrl($url);

            return $safeUrl === '' ? '' : $this->generateTag($safeUrl, $type);
        }

        return $this->generateTag($localUrl, $type);
    }

    /**
     * Checks if a given URL belongs to the same host as the application.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL is local, false otherwise.
     */
    protected function isLocalUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);
        $parsedAppUrl = parse_url($this->appUrl);

        if (isset($parsedUrl['host'], $parsedAppUrl['host'])) {
            return $parsedUrl['host'] === $parsedAppUrl['host'];
        }

        return true;
    }

    /**
     * Generates the appropriate HTML tag (link, script, or img) for an asset.
     *
     * @param string $url The URL of the asset.
     * @param string $type The asset type ('css', 'js', or 'img').
     * @return string The HTML tag.
     */
    protected function generateTag(string $url, string $type): string
    {
        $safeUrl = $this->escapeHtmlAttribute($url);

        switch ($type) {
            case 'css':
                return "<link href=\"{$safeUrl}\" rel=\"stylesheet\">";
            case 'js':
                return "<script src=\"{$safeUrl}\" defer></script>";
            case 'img':
                return "<img src=\"{$safeUrl}\" alt=\"\" loading=\"lazy\">";
            default:
                return '';
        }
    }

    /**
     * Downloads and caches an external asset from a CDN, storing it locally.
     *
     * @param string $url The URL of the CDN asset.
     * @param string $type The asset type ('js' by default).
     * @return string The URL of the cached local asset.
     */
    protected function processCdnAsset(string $url, string $type = "js"): string
    {
        $normalizedUrl = $this->sanitizeRemoteUrl($url);
        if ($normalizedUrl === '') {
            return '';
        }

        if (!$this->isBasicRemoteUrlSafe($normalizedUrl)) {
            return '';
        }

        $allowedExtensions = [
            'css' => ['css'],
            'js' => ['js', 'mjs'],
            'img' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
        ];

        $type = array_key_exists($type, $allowedExtensions) ? $type : 'js';
        $path = parse_url($normalizedUrl, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowedForType = $allowedExtensions[$type];

        if ($extension === '') {
            $extension = $allowedForType[0];
        } elseif (!in_array($extension, $allowedForType, true)) {
            return $normalizedUrl;
        }

        $hash = sha1($normalizedUrl);
        $localPath = "assets/{$type}/cache/{$hash}.{$extension}";
        $fullLocalPath = BASE_PATH . "public/" . $localPath;

        if (!$this->fileExists($fullLocalPath)) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $this->remoteAssetTimeout,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'https' => [
                    'timeout' => $this->remoteAssetTimeout,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
            ]);

            $content = @file_get_contents($normalizedUrl, false, $context);
            if ($content === false) {
                logs('templates')->warning('Failed to fetch remote asset: ' . $normalizedUrl);

                return '';
            }
            if ($this->remoteAssetMaxBytes > 0 && strlen($content) > $this->remoteAssetMaxBytes) {
                logs('security')->warning('Remote asset exceeds max size and was blocked', [
                    'url' => $normalizedUrl,
                    'size' => strlen($content),
                    'max' => $this->remoteAssetMaxBytes,
                ]);

                return '';
            }
            $this->saveAsset($fullLocalPath, $content);
        }

        $version = $this->fileMtime($fullLocalPath);

        return url($localPath) . "?v={$version}";
    }

    /**
     * Prepare allowed remote host list from configuration.
     */
    // Whitelist intentionally removed by request; keeping only basic checks above.

    /**
     * Saves asset content to a specified path, with optional minification.
     *
     * @param string $path The path where the asset will be saved.
     * @param string $content The content to save.
     */
    protected function saveAsset(string $path, string $content): void
    {
        $content = $this->minifyContent($path, $content);
        $this->ensureDirectoryExists(dirname($path));

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            logs()->error("Failed to write asset to path: {$path}");

            return;
        }

        $this->invalidateFsCache($path);
    }

    /**
     * Copies an asset from a source path to a destination path, creating directories if necessary.
     *
     * @param string $sourcePath The source file path.
     * @param string $destinationPath The destination file path.
     */
    protected function copyAsset(string $sourcePath, string $destinationPath): void
    {
        $this->ensureDirectoryExists(dirname($destinationPath));

        if (!copy($sourcePath, $destinationPath)) {
            logs()->error("Failed to copy asset from {$sourcePath} to {$destinationPath}");

            return;
        }

        $this->invalidateFsCache($destinationPath);
    }

    /**
     * Minifies asset content if minification is enabled, based on the asset's file type.
     *
     * @param string $path The path to the asset file.
     * @param string $content The content to minify.
     * @return string The minified content.
     */
    protected function minifyContent(string $path, string $content): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'js' && $this->minifyAssets) {
            $minifier = new Minify\JS();
            $minifier->add($content);

            return $minifier->minify();
        }

        if ($extension === 'css' && $this->minifyAssets) {
            if ($this->autoprefixAssets) {
                // Skip autoprefixing if stylesheet is too large to avoid timeouts
                if ($this->autoprefixMaxBytes > 0 && strlen($content) > $this->autoprefixMaxBytes) {
                    logs()->warning(sprintf('Autoprefix skipped: CSS size %d bytes exceeds limit %d', strlen($content), $this->autoprefixMaxBytes));
                } else {
                    // Performance issue with autoprefixer in debug mode
                    if (!is_debug()) {
                        $autoprefixer = new Autoprefixer($content);

                        try {
                            $content = $autoprefixer->compile();
                        } catch (Throwable $e) {
                            logs()->error("Autoprefixer failed: " . $e->getMessage());
                        }
                    }
                }
            }

            if ($content === '') {
                return '';
            }

            $minifier = new Minify\CSS();
            $minifier->add($content);

            return $minifier->minify();
        }

        return $content;
    }

    /**
     * Ensure that a specified directory exists, creating it if necessary.
     *
     * @param string $directory The path of the directory to check or create.
     */
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
    }

    /**
     * Determine tag "type" for generateTag() from a file extension.
     */
    private function getTagTypeFromExtension(string $extension): string
    {
        $extension = strtolower($extension);

        return self::EXTENSION_TO_TYPE[$extension] ?? '';
    }

    /**
     * Cache-busted URL for a relative public path.
     */
    private function buildPublicAssetUrl(string $relativePublicPath): string
    {
        $relativePublicPath = ltrim(str_replace('\\', '/', $relativePublicPath), '/');
        $fullPath = BASE_PATH . "public/{$relativePublicPath}";
        if (!$this->fileExists($fullPath)) {
            return '';
        }

        $version = $this->fileMtime($fullPath);

        return url($relativePublicPath) . "?v={$version}";
    }

    /**
     * Execute a callback under an exclusive lock file (best-effort).
     * If lock can't be acquired, waits for the lock holder to finish by taking a shared lock.
     */
    private function withFileLock(string $lockFile, callable $callback): void
    {
        $handle = @fopen($lockFile, 'w+');
        if ($handle === false) {
            $callback();

            return;
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            try {
                $callback();
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
                @unlink($lockFile);
            }

            return;
        }

        // Wait for the lock holder (compile/write) to finish.
        flock($handle, LOCK_SH);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Apply theme.json asset replacement rules to the asset expression.
     * Supports:
     *  - asset_replacements: direct mapping
     *  - asset_module_replacements: regex mapping
     *  - asset_wildcard_replacements: fnmatch-style mapping
     */
    private function applyAssetReplacement(string $expression): string
    {
        // normalize / and \
        $expression = str_replace(['/', '\\'], '/', $expression);
        $normalizeBasePath = str_replace(['/', '\\'], '/', BASE_PATH);

        $expression = str_replace([$normalizeBasePath, '/app/'], ['', ''], $expression);

        try {
            /** @var ThemeManager $themeManager */
            $themeManager = app(ThemeManager::class);
            $themeData = $themeManager->getThemeData($themeManager->getCurrentTheme()) ?? [];

            // 1) Direct mappings
            $replacements = $themeData['asset_replacements'] ?? [];
            if (isset($replacements[$expression])) {
                return (string) $replacements[$expression];
            }

            // 2) Regex mappings
            $regexReplacements = $themeData['asset_module_replacements'] ?? [];
            foreach ($regexReplacements as $pattern => $replacement) {
                // suppress invalid pattern warnings
                $ok = @preg_match($pattern, $expression);
                if ($ok === 1) {
                    $new = @preg_replace($pattern, (string) $replacement, $expression);
                    if (is_string($new) && $new !== '') {
                        return $new;
                    }
                }
            }

            // 3) Wildcard mappings
            $wildcardReplacements = $themeData['asset_wildcard_replacements'] ?? [];
            foreach ($wildcardReplacements as $pattern => $replacement) {
                if (fnmatch($pattern, $expression)) {
                    $base = basename($expression);

                    return str_replace('*', $base, (string) $replacement);
                }
            }
        } catch (Throwable $e) {
            logs('templates')->error('Asset replacement failed: ' . $e->getMessage());
        }

        return $expression;
    }

    /**
     * Load additional SCSS files to append from theme.json.
     * Expected structure in theme.json:
     * {
     *   "asset_scss_append": {
     *     "main": ["Themes/mytheme/assets/sass/overrides.scss", ...],
     *     "admin": ["Themes/mytheme/assets/sass/admin-overrides.scss", ...]
     *   }
     * }
     */
    private function loadThemeScssAppends(): void
    {
        try {
            /** @var ThemeManager $themeManager */
            $themeManager = app(ThemeManager::class);
            $themeData = $themeManager->getThemeData($themeManager->getCurrentTheme()) ?? [];

            $append = $themeData['asset_scss_append'] ?? [];

            // Backward-compatible: allow a flat array to mean current context
            if (isset($append[0]) && is_string($append[0])) {
                $append = [
                    $this->context => $append,
                ];
            }

            foreach (['main', 'admin'] as $ctx) {
                if (!empty($append[$ctx]) && is_array($append[$ctx])) {
                    foreach ($append[$ctx] as $expr) {
                        if (!is_string($expr) || trim($expr) === '') {
                            continue;
                        }

                        $resolved = $this->resolveFilePath($expr);
                        $this->addScssFile($resolved, $ctx);
                    }
                }
            }
        } catch (Throwable $e) {
            logs('templates')->error('Failed to load theme SCSS appends: ' . $e->getMessage());
        }
    }

    /**
     * Extracts just the URL from a generated HTML tag.
     *
     * @param string $htmlTag The HTML tag containing the URL.
     * @return string The extracted URL.
     */
    private function extractUrl(string $htmlTag): string
    {
        if (preg_match('/(?:href|src)=["\'](.*?)["\']/i', $htmlTag, $matches)) {
            return $matches[1];
        }

        return $htmlTag;
    }

    /**
     * Resolves the file path for a given asset expression with fallback support.
     *
     * @param string $expression The relative or absolute path of the asset.
     * @return string The resolved file path.
     */
    private function resolveFilePath(string $expression): string
    {
        if (strpos($expression, BASE_PATH) !== false) {
            return $this->containsPathTraversal($expression) ? '' : $expression;
        }

        // Support expressions that already start with 'app/...'
        if (strpos($expression, 'app/') === 0) {
            return $this->containsPathTraversal($expression) ? '' : path($expression);
        }

        // Try to find with fallback for theme assets
        if (strpos($expression, 'Themes/') === 0) {
            $pathParts = explode('/', $expression);
            if (count($pathParts) >= 4) {
                $theme = $pathParts[1];
                $type = $pathParts[3]; // assets/scripts, assets/sass, etc.
                $relativePath = implode('/', array_slice($pathParts, 4));

                $foundPath = $this->findAssetWithFallback($relativePath, $type);
                if ($foundPath) {
                    return $foundPath;
                }
            }
        }

        if ($this->containsPathTraversal($expression)) {
            return '';
        }

        return BASE_PATH . "app/" . ltrim($expression, '/');
    }

    /**
     * Retrieves the file extension of an asset from its path or URL.
     *
     * @param string $expression The asset path or URL.
     * @param string $filePath The full path to the asset file.
     * @return string The file extension in lowercase.
     */
    private function getFileExtension(string $expression, string $filePath): string
    {
        $path = parse_url($expression, PHP_URL_PATH) ?: $filePath;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Generates the full URL for an asset and adds a version query based on the last modification time.
     *
     * @param string $path The relative path of the asset.
     * @param bool $urlOnly Whether to return only the URL instead of the full HTML tag.
     * @return string The URL with a version query or the HTML tag.
     */
    private function generateAssetUrl(string $path, bool $urlOnly = false): string
    {
        $url = $this->buildPublicAssetUrl($path);
        if ($url === '') {
            return '';
        }

        if ($urlOnly) {
            return $url;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = $this->getTagTypeFromExtension($extension);
        if ($type === '') {
            return '';
        }

        return $this->generateTag($url, $type);
    }

    /**
     * Processes an asset by extension, handling SCSS, CSS, JS, and images differently.
     *
     * @param string $extension The file extension.
     * @param string $expression The path or URL of the asset.
     * @param string $filePath The resolved file path of the asset.
     * @param bool $urlOnly Whether to return only the URL instead of the full HTML tag.
     * @return string The generated HTML tag or asset URL.
     */
    private function processAssetBasedOnExtension(string $extension, string $expression, string $filePath, bool $urlOnly = false): string
    {
        switch ($extension) {
            case 'scss':
                return $this->processScssAsset($expression, $filePath);
            case 'css':
                return $this->processCssAsset($expression, $filePath);
            case 'js':
                return $this->processJsAsset($expression, $filePath, $urlOnly);
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'svg':
                return $this->processImageAsset($expression, $filePath, $extension, $urlOnly);
            default:
                return '';
        }
    }

    /**
     * Optimized SCSS compilation with enhanced caching and fallback.
     */
    private function processScssAsset(string $expression, string $scssPath): string
    {
        // Try fallback resolution if file doesn't exist
        if (!$this->fileExists($scssPath)) {
            $pathParts = explode('/', $expression);
            if (count($pathParts) >= 4 && $pathParts[0] === 'Themes') {
                $relativePath = implode('/', array_slice($pathParts, 4));
                $fallbackPath = $this->findAssetWithFallback($relativePath, 'sass');
                if ($fallbackPath) {
                    $scssPath = $fallbackPath;
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        $cacheKey = sha1($scssPath . implode(',', $this->additionalScssFiles[$this->context]) . implode(',', $this->additionalPartials) . $this->context);

        $cssCacheDir = $this->getCacheDir('css');
        $cssPath = $cssCacheDir . "{$cacheKey}.css";
        $cssFullPath = BASE_PATH . "public/" . $cssPath;

        $cssStaleCacheDir = $this->getStaleCacheDir('css');
        $cssStalePath = $cssStaleCacheDir . "{$cacheKey}.css";
        $cssStaleFullPath = BASE_PATH . "public/" . $cssStalePath;

        $this->ensureDirectoryExists(dirname($cssFullPath));

        $cssMtime = $this->fileMtime($cssFullPath);
        $cssStaleMtime = $this->fileMtime($cssStaleFullPath);
        $scssMtime = $this->fileMtime($scssPath);

        $latestSourceMtime = max($scssMtime, $this->getScssDependenciesMaxMtime($scssPath));

        foreach ($this->additionalScssFiles[$this->context] as $additionalFile) {
            if ($this->fileExists($additionalFile)) {
                $latestSourceMtime = max($latestSourceMtime, $this->fileMtime($additionalFile));
            }
        }

        foreach ($this->additionalPartials as $partial) {
            $partialPath = path($partial);
            if ($this->fileExists($partialPath)) {
                $latestSourceMtime = max($latestSourceMtime, $this->fileMtime($partialPath));
            }
        }

        $needsRecompile = $cssMtime === 0
            || $latestSourceMtime >= $cssMtime
            || $this->debugMode;

        if ($needsRecompile) {
            $lockFile = $cssFullPath . '.lock';

            // In debug mode we want immediate recompilation for accurate feedback.
            if ($this->debugMode) {
                $this->withFileLock($lockFile, function () use ($scssPath, $cssFullPath): void {
                    $this->compileScssToCacheFile($scssPath, $cssFullPath);
                });

                if (!$this->fileExists($cssFullPath)) {
                    $this->compileScssToCacheFile($scssPath, $cssFullPath);
                }
            } else {
                // SWR: serve existing (or stale) CSS and revalidate after response.
                SWRQueue::queue('assets.scss.' . $cacheKey, function () use ($lockFile, $scssPath, $cssFullPath, $latestSourceMtime): void {
                    $this->withFileLock($lockFile, function () use ($scssPath, $cssFullPath, $latestSourceMtime): void {
                        $cssMtime = $this->fileMtime($cssFullPath);
                        if ($cssMtime !== 0 && $latestSourceMtime < $cssMtime) {
                            return;
                        }

                        $this->compileScssToCacheFile($scssPath, $cssFullPath);
                    });
                });
            }
        }

        if (!$needsRecompile && isset($this->compilationCache[$cacheKey]) && !$this->debugMode) {
            return $this->compilationCache[$cacheKey];
        }

        $servedPath = $cssPath;
        $servedVersion = $cssMtime;

        // If the fresh cache file doesn't exist yet, try serving the stale cache while it revalidates.
        if ($cssMtime === 0 && $cssStaleMtime > 0 && !$this->debugMode) {
            $servedPath = $cssStalePath;
            $servedVersion = $cssStaleMtime;
        }

        // If nothing exists to serve, compile synchronously as a last resort.
        if ($servedVersion === 0) {
            $this->compileScssToCacheFile($scssPath, $cssFullPath);
            $cssMtime = $this->fileMtime($cssFullPath);
            $servedPath = $cssPath;
            $servedVersion = $cssMtime ?: time();
        }

        $url = $this->escapeHtmlAttribute(url($servedPath) . "?v={$servedVersion}");
        $result = "<link href=\"{$url}\" rel=\"stylesheet\">";

        if (!$this->debugMode && !$needsRecompile && $servedPath === $cssPath) {
            $this->compilationCache[$cacheKey] = $result;
        }

        return $result;
    }

    private function compileScssToCacheFile(string $scssPath, string $cssFullPath): void
    {
        $importPaths = [dirname($scssPath)];
        foreach ($this->additionalScssFiles[$this->context] as $additionalFile) {
            if ($this->fileExists($additionalFile)) {
                $importPaths[] = dirname($additionalFile);
            }
        }
        foreach ($this->additionalPartials as $partial) {
            $partialPath = path($partial);
            if ($this->fileExists($partialPath)) {
                $importPaths[] = dirname($partialPath);
            }
        }
        $importPaths[] = rtrim(str_replace('\\', '/', BASE_PATH . 'app'), '/');

        $baseImportPaths = $this->scssCompiler->getBaseImportPaths();
        $this->scssCompiler->setImportPaths($baseImportPaths);
        $importPaths = array_map(static fn ($p) => str_replace('\\', '/', $p), $importPaths);
        foreach (array_unique($importPaths) as $importPath) {
            if (is_dir($importPath)) {
                $this->scssCompiler->addImportPath($importPath);
            }
        }

        $scssContents = $this->gatherScssContents($scssPath);
        $css = $this->compileScss($scssContents);

        if ($css !== '') {
            $this->saveAsset($cssFullPath, $css);
        }
    }

    /**
     * Return max mtime among an SCSS file and its imports.
     */
    private function getScssDependenciesMaxMtime(string $scssPath): int
    {
        $visited = [];
        $maxMtime = 0;

        $paths = $this->collectScssDependencies($scssPath, $visited);

        if (is_development()) {
            logs('templates')->debug('SCSS dependencies for ' . $scssPath . ': ' . json_encode($paths));
        }

        foreach ($paths as $path) {
            $mtime = $this->fileMtime($path);
            if ($mtime > $maxMtime) {
                $maxMtime = $mtime;
            }
        }

        return $maxMtime;
    }

    /**
     * Collect SCSS dependency paths via simple @import/@use/@forward parsing (recursive).
     */
    private function collectScssDependencies(string $scssPath, array &$visited): array
    {
        $real = realpath($scssPath) ?: $scssPath;
        $real = str_replace('\\', '/', $real);

        if (isset($visited[$real])) {
            return [];
        }

        $visited[$real] = true;
        $dependencies = [$real];

        $content = $this->readFile($real);
        if ($content === false) {
            return $dependencies;
        }

        if (preg_match_all('/@(import|use|forward)\s+([^;]+);/i', $content, $matches)) {
            foreach ($matches[2] as $rawImports) {
                foreach (explode(',', $rawImports) as $importExpr) {
                    $importExpr = str_replace('\\', '/', trim($importExpr));
                    $importExpr = trim($importExpr, '\'"');

                    if ($importExpr === '') {
                        continue;
                    }

                    if (str_starts_with($importExpr, 'http')
                        || str_contains($importExpr, 'url(')
                        || str_ends_with($importExpr, '.css')) {
                        continue;
                    }

                    $candidates = $this->resolveScssImportCandidates($importExpr, dirname($real));

                    $found = false;
                    foreach ($candidates as $candidate) {
                        if ($this->fileExists($candidate)) {
                            $dependencies = array_merge(
                                $dependencies,
                                $this->collectScssDependencies($candidate, $visited)
                            );
                            $found = true;

                            break;
                        }
                    }

                    if (!$found && is_development()) {
                        logs('templates')->warning("SCSS import '{$importExpr}' not found. Tried: " . json_encode($candidates));
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Build candidate file paths for a SCSS import relative to base dir and app root.
     */
    private function resolveScssImportCandidates(string $importExpr, string $baseDir): array
    {
        $clean = str_replace(['"', '\''], '', $importExpr);
        $clean = str_replace('\\', '/', $clean);

        $dirs = [
            rtrim(str_replace('\\', '/', $baseDir), '/'),
            rtrim(str_replace('\\', '/', BASE_PATH . 'app'), '/'),
        ];

        $candidates = [];

        foreach ($dirs as $dir) {
            $path = $dir . '/' . $clean;

            foreach ([$path, "{$path}.scss", "{$path}.sass"] as $candidate) {
                $candidates[] = str_replace('\\', '/', $candidate);
            }

            $parts = explode('/', $clean);
            $file = array_pop($parts);
            $prefix = implode('/', $parts);
            $partialBase = $prefix === '' ? "{$dir}/_{$file}" : "{$dir}/{$prefix}/_{$file}";

            foreach ([$partialBase, "{$partialBase}.scss", "{$partialBase}.sass"] as $candidate) {
                $candidates[] = str_replace('\\', '/', $candidate);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Compiles SCSS contents into CSS, catching any compilation errors.
     *
     * @param array $scssContents An array of SCSS content strings.
     * @return string The compiled CSS string.
     */
    private function compileScss(array $scssContents): string
    {
        $scssContent = implode("\n", $scssContents);

        $start = microtime(true);

        try {
            $css = $this->scssCompiler->compileString($scssContent)->getCss();

            self::$assetsCompileTime += microtime(true) - $start;

            return $css;
        } catch (SassException $e) {
            $message = sprintf("SCSS compilation error: %s", $e);

            if ($this->debugMode) {
                throw new CompilerException($message, 0, null);
            }
            logs()->error($message);

        }

        return '';
    }

    /**
     * Loads shared SCSS partials, which are included in all SCSS compilations.
     *
     * @return string The combined contents of shared partial files.
     */
    private function loadSharedPartials(): string
    {
        $fingerprintParts = [];
        foreach ($this->additionalPartials as $partialPath) {
            $fullPath = path($partialPath);
            $fingerprintParts[] = $fullPath . ':' . $this->fileMtime($fullPath);
        }

        $cacheKey = sha1(implode('|', $fingerprintParts));
        if (isset($this->sharedPartialsCache[$cacheKey])) {
            return $this->sharedPartialsCache[$cacheKey];
        }

        $partialsContent = '';

        foreach ($this->additionalPartials as $partialPath) {
            $partialPath = path($partialPath);

            if ($this->fileExists($partialPath)) {
                $content = $this->readFile($partialPath);
                if ($content !== false) {
                    $partialsContent .= $content . "\n";
                } else {
                    logs()->warning("Unable to read SCSS partial: {$partialPath}");
                }
            } else {
                logs()->warning("SCSS partial not found: {$partialPath}");
            }
        }

        $this->sharedPartialsCache[$cacheKey] = $partialsContent;

        return $partialsContent;
    }

    /**
     * Processes a CSS file, ensuring it's cached and returning the appropriate HTML link tag.
     *
     * @param string $expression The asset expression.
     * @param string $cssPathBase The path to the CSS file.
     * @return string The generated HTML link tag for the CSS file.
     */
    private function processCssAsset(string $expression, string $cssPathBase): string
    {
        if (Validators::isUrl($expression)) {
            return $this->processRemoteAsset($expression, 'css');
        }

        if (!$this->fileExists($cssPathBase)) {
            return '';
        }

        $hash = sha1($cssPathBase);
        $cssPath = self::CSS_CACHE_DIR . "{$hash}.css";
        $cssFullPath = BASE_PATH . "public/" . $cssPath;

        if (!$this->fileExists($cssFullPath) || $this->fileMtime($cssPathBase) > $this->fileMtime($cssFullPath)) {
            $content = $this->readFile($cssPathBase);
            if ($content === false) {
                logs()->error("Unable to read CSS file: {$cssPathBase}");

                return '';
            }
            $this->saveAsset($cssFullPath, $content);
        }

        $version = $this->fileMtime($cssFullPath);
        $url = $this->escapeHtmlAttribute(url($cssPath) . "?v={$version}");

        return "<link href=\"{$url}\" rel=\"stylesheet\">";
    }

    /**
     * Process JS asset with fallback support.
     */
    private function processJsAsset(string $expression, string $jsPathBase, bool $urlOnly = false): string
    {
        if (Validators::isUrl($expression)) {
            return $this->processRemoteAsset($expression, 'js');
        }

        // Try fallback resolution if file doesn't exist
        if (!$this->fileExists($jsPathBase)) {
            $pathParts = explode('/', $expression);
            if (count($pathParts) >= 4 && $pathParts[0] === 'Themes') {
                $relativePath = implode('/', array_slice($pathParts, 4));
                $fallbackPath = $this->findAssetWithFallback($relativePath, 'scripts');
                if ($fallbackPath) {
                    $jsPathBase = $fallbackPath;
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        $hash = sha1($jsPathBase);
        $jsPath = self::JS_CACHE_DIR . "{$hash}.js";
        $jsFullPath = BASE_PATH . "public/" . $jsPath;

        if (!$this->fileExists($jsFullPath) || $this->fileMtime($jsPathBase) > $this->fileMtime($jsFullPath)) {
            $lockFile = $jsFullPath . '.lock';
            $this->withFileLock($lockFile, function () use ($jsPathBase, $jsFullPath) {
                if ($this->fileExists($jsFullPath) && $this->fileMtime($jsPathBase) <= $this->fileMtime($jsFullPath)) {
                    return;
                }

                $content = $this->readFile($jsPathBase);
                if ($content === false) {
                    logs()->error("Unable to read JS file: {$jsPathBase}");

                    return;
                }
                $this->saveAsset($jsFullPath, $content);
            });
        }

        $version = $this->fileMtime($jsFullPath);
        $url = $this->escapeHtmlAttribute(url($jsPath) . "?v={$version}");

        return $urlOnly ? $url : "<script src=\"{$url}\" defer></script>";
    }

    /**
     * Process image asset with fallback support.
     */
    private function processImageAsset(string $expression, string $imgPathBase, string $extension, bool $urlOnly = false): string
    {
        if (Validators::isUrl($expression)) {
            return $this->processRemoteAsset($expression, 'img');
        }

        // Try fallback resolution if file doesn't exist
        if (!$this->fileExists($imgPathBase) || !in_array($extension, self::SUPPORTED_IMAGE_EXTENSIONS)) {
            $pathParts = explode('/', $expression);
            if (count($pathParts) >= 4 && $pathParts[0] === 'Themes') {
                $relativePath = implode('/', array_slice($pathParts, 4));
                $fallbackPath = $this->findAssetWithFallback($relativePath, 'images');
                if ($fallbackPath && in_array($extension, self::SUPPORTED_IMAGE_EXTENSIONS)) {
                    $imgPathBase = $fallbackPath;
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        $hash = $this->debugMode ? pathinfo($expression, PATHINFO_FILENAME) : sha1($expression);
        $imgPath = self::IMG_CACHE_DIR . "{$hash}.{$extension}";
        $imgFullPath = BASE_PATH . "public/" . $imgPath;

        if (in_array($extension, ['png', 'jpg', 'jpeg']) && config('app.convert_to_webp')) {
            $webpPath = self::IMG_CACHE_DIR . "{$hash}.webp";
            $webpFullPath = BASE_PATH . "public/" . $webpPath;

            if (!$this->fileExists($webpFullPath) || $this->fileMtime($imgPathBase) > $this->fileMtime($webpFullPath)) {
                $lockFile = $webpFullPath . '.lock';

                try {
                    $this->withFileLock($lockFile, static function () use ($imgPathBase, $webpFullPath) {
                        if (file_exists($webpFullPath) && filemtime($imgPathBase) <= filemtime($webpFullPath)) {
                            return;
                        }

                        WebPConvert::convert($imgPathBase, $webpFullPath);
                    });
                } catch (Exception $e) {
                    logs()->error($e->getMessage());

                    return $this->generateAssetUrl($imgPath);
                }

                $this->invalidateFsCache($webpFullPath);
            }

            $imgPath = $webpPath;
            $imgFullPath = $webpFullPath;
        }

        if (!$this->fileExists($imgFullPath) || $this->fileMtime($imgPathBase) > $this->fileMtime($imgFullPath)) {
            $this->copyAsset($imgPathBase, $imgFullPath);
        }

        $url = $this->escapeHtmlAttribute(url($imgPath));

        return $urlOnly ? $url : "<img src=\"{$url}\" alt=\"\" loading=\"lazy\">";
    }

    /**
     * Determine if the remote asset can be cached server-side.
     */
    private function isBasicRemoteUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (!$this->isHostAllowed($host)) {
            return false;
        }

        return $this->isResolvedHostPublic($host);
    }

    /**
     * Extract sanitized remote URL without credentials.
     */
    private function sanitizeRemoteUrl(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            return '';
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return '';
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return '';
        }

        $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . '://' . strtolower($host) . $port . $path . $query . $fragment;
    }

    private function containsPathTraversal(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, "\0")
            || (bool) preg_match('#(^|/)\.\.(/|$)#', $normalized);
    }

    private function escapeHtmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isHostAllowed(string $host): bool
    {
        if (empty($this->allowedRemoteHosts)) {
            return true;
        }

        foreach ($this->allowedRemoteHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function isResolvedHostPublic(string $host): bool
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false || empty($records)) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || !$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function fileExists(string $path): bool
    {
        if (!array_key_exists($path, $this->fileExistsCache)) {
            $this->fileExistsCache[$path] = file_exists($path);
        }

        return $this->fileExistsCache[$path];
    }

    private function fileMtime(string $path): int
    {
        if (!$this->fileExists($path)) {
            return 0;
        }

        if (!array_key_exists($path, $this->fileMtimeCache)) {
            $this->fileMtimeCache[$path] = (int) (@filemtime($path) ?: 0);
        }

        return $this->fileMtimeCache[$path];
    }

    private function readFile(string $path): string|false
    {
        if (!$this->fileExists($path)) {
            return false;
        }

        return @file_get_contents($path);
    }

    private function invalidateFsCache(string $path): void
    {
        unset($this->fileExistsCache[$path], $this->fileMtimeCache[$path]);
    }

}
