<?php

declare(strict_types=1);

namespace App\Services;

class VersionChecker
{
    const REPO = 'abdelhamiderrahmouni/cnkill';

    const API_URL = 'https://api.github.com/repos/abdelhamiderrahmouni/cnkill/releases/latest';

    const CACHE_TTL = 86400; // 24 hours

    const FETCH_TIMEOUT = 3; // seconds

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    /**
     * Resolve the directory used for caching version data.
     * Respects XDG_CACHE_HOME on Linux; falls back to ~/.cache on all platforms.
     */
    public function getCacheDir(): string
    {
        $xdg = rtrim((string) ($_SERVER['XDG_CACHE_HOME'] ?? ''), DIRECTORY_SEPARATOR);
        $home = rtrim((string) ($_SERVER['HOME'] ?? ''), DIRECTORY_SEPARATOR);

        $base = $xdg !== '' ? $xdg : ($home !== '' ? $home . DIRECTORY_SEPARATOR . '.cache' : sys_get_temp_dir());

        return $base . DIRECTORY_SEPARATOR . 'cnkill';
    }

    /**
     * Full path of the version cache JSON file.
     */
    public function getCachePath(): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . 'version_check.json';
    }

    /**
     * Read the cached latest version tag.
     * Returns null when the cache file is missing, unreadable, or malformed.
     * Does NOT check TTL — callers decide whether staleness matters.
     */
    public function readCache(): ?array
    {
        $path = $this->getCachePath();

        if (! file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['latest'], $data['checked_at'])) {
            return null;
        }

        return $data;
    }

    /**
     * Write a version check result to the cache.
     */
    public function writeCache(string $latestTag): void
    {
        $dir = $this->getCacheDir();

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->getCachePath(),
            json_encode(['latest' => $latestTag, 'checked_at' => time()], JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Return the cached latest tag only when the cache is still fresh.
     * Returns null when missing, malformed, or older than CACHE_TTL.
     */
    public function getCachedLatest(): ?string
    {
        $data = $this->readCache();

        if ($data === null) {
            return null;
        }

        if ((time() - (int) $data['checked_at']) > self::CACHE_TTL) {
            return null;
        }

        return (string) $data['latest'];
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    /**
     * Fetch the latest release tag from GitHub and update the cache.
     * Returns the tag string (e.g. "v0.5.0") or null on failure.
     */
    public function fetchLatest(): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: cnkill-updater',
                    'Accept: application/vnd.github+json',
                ]),
                'timeout' => self::FETCH_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(self::API_URL, false, $context);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $tag = (string) $data['tag_name'];

        $this->writeCache($tag);

        return $tag;
    }

    /**
     * Return the latest release tag, using the cache when fresh.
     * Falls back to a live GitHub fetch when the cache is stale or missing.
     * Returns null when the fetch fails.
     */
    public function getLatest(): ?string
    {
        return $this->getCachedLatest() ?? $this->fetchLatest();
    }

    // -------------------------------------------------------------------------
    // Version comparison
    // -------------------------------------------------------------------------

    /**
     * Normalise a version tag for comparison (strips leading "v").
     */
    private function normalise(string $version): string
    {
        return ltrim($version, 'v');
    }

    /**
     * Return true when $candidate is a newer version than $current.
     */
    public function isNewer(string $candidate, string $current): bool
    {
        return version_compare($this->normalise($candidate), $this->normalise($current), '>');
    }

    /**
     * Return the latest tag if it is newer than $current, otherwise null.
     * Uses the cache when fresh; performs a live fetch when stale.
     */
    public function getAvailableUpgrade(string $current): ?string
    {
        $latest = $this->getLatest();

        if ($latest === null) {
            return null;
        }

        return $this->isNewer($latest, $current) ? $latest : null;
    }

    /**
     * Like getAvailableUpgrade() but reads only from cache — no HTTP call.
     * Returns null when the cache is missing, malformed, stale, or up to date.
     */
    public function getAvailableUpgradeFromCache(string $current): ?string
    {
        $data = $this->readCache();

        if ($data === null) {
            return null;
        }

        $latest = (string) $data['latest'];

        return $this->isNewer($latest, $current) ? $latest : null;
    }

    /**
     * Return true when the cache is missing or older than CACHE_TTL.
     */
    public function isCacheStale(): bool
    {
        $data = $this->readCache();

        if ($data === null) {
            return true;
        }

        return (time() - (int) $data['checked_at']) > self::CACHE_TTL;
    }

    // -------------------------------------------------------------------------
    // Install method detection
    // -------------------------------------------------------------------------

    /**
     * Return the real path of the currently running binary.
     */
    public function getCurrentBinaryPath(): string
    {
        return (string) (realpath($_SERVER['argv'][0] ?? '') ?: ($_SERVER['argv'][0] ?? ''));
    }

    /**
     * Return true when cnkill was installed via `composer global require`.
     *
     * Detection: the resolved binary path contains the Composer vendor path
     * segment for this package (vendor/abdelhamiderrahmouni/cnkill).
     */
    public function isComposerInstall(): bool
    {
        $binary = $this->getCurrentBinaryPath();

        $needle = implode(DIRECTORY_SEPARATOR, ['vendor', 'abdelhamiderrahmouni', 'cnkill']);

        return str_contains($binary, $needle);
    }

    // -------------------------------------------------------------------------
    // Platform detection
    // -------------------------------------------------------------------------

    /**
     * Return the OS identifier used in release asset names, or null if unsupported.
     */
    public function getOs(): ?string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => null,
        };
    }

    /**
     * Return the architecture identifier used in release asset names, or null if unsupported.
     */
    public function getArch(): ?string
    {
        return match (php_uname('m')) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => null,
        };
    }

    /**
     * Construct the GitHub release asset download URL for the given tag.
     * Returns null when the current platform is unsupported.
     */
    public function getDownloadUrl(string $tag): ?string
    {
        $os = $this->getOs();
        $arch = $this->getArch();

        if ($os === null || $arch === null) {
            return null;
        }

        $asset = "cnkill-{$os}-{$arch}";

        return 'https://github.com/' . self::REPO . "/releases/download/{$tag}/{$asset}";
    }
}
