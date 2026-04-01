<?php

declare(strict_types=1);

namespace App\Services;

class VersionChecker
{
    private const REPO = 'abdelhamiderrahmouni/cnkill';

    private const API_URL = 'https://api.github.com/repos/abdelhamiderrahmouni/cnkill/releases/latest';

    private const CACHE_TTL = 86400; // 24 hours

    private const FETCH_TIMEOUT = 3; // seconds

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    /**
     * Resolve the directory used for caching version data.
     * Respects XDG_CACHE_HOME; falls back to ~/.cache on all platforms.
     */
    public function getCacheDir(): string
    {
        $xdg = rtrim((string) ($_SERVER['XDG_CACHE_HOME'] ?? ''), DIRECTORY_SEPARATOR);
        $home = rtrim((string) ($_SERVER['HOME'] ?? ''), DIRECTORY_SEPARATOR);

        if ($xdg !== '') {
            $base = $xdg;
        } elseif ($home !== '') {
            $base = $home . DIRECTORY_SEPARATOR . '.cache';
        } else {
            $base = sys_get_temp_dir();
        }

        return $base . DIRECTORY_SEPARATOR . 'cnkill';
    }

    public function getCachePath(): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . 'version_check.json';
    }

    /**
     * Read the raw cache data. Returns null when missing, unreadable, or malformed.
     * Does NOT enforce TTL — callers decide.
     */
    public function readCache(): ?array
    {
        $raw = @file_get_contents($this->getCachePath());

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['latest'], $data['checked_at'])) {
            return null;
        }

        return $data;
    }

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
     * Return the cached tag only when it is still within the TTL.
     * Returns null when missing, malformed, or expired.
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
     * Fetch the latest release tag from GitHub, cache the result, and return it.
     * Returns null on any failure.
     *
     * Uses the system curl binary when available — required for standalone
     * micro.sfx builds, which lack the openssl extension and cannot make HTTPS
     * requests via PHP stream wrappers.  Falls back to file_get_contents for
     * environments where curl is not on PATH (e.g. Composer-global installs
     * running under a full PHP with openssl).
     */
    public function fetchLatest(): ?string
    {
        $raw = trim((string) shell_exec('command -v curl 2>/dev/null')) !== ''
            ? $this->fetchViaSystemCurl()
            : $this->fetchViaFileGetContents();

        if ($raw === null) {
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
     * Perform the GitHub API request using the system curl binary.
     * Returns the raw response body, or null on failure.
     */
    private function fetchViaSystemCurl(): ?string
    {
        $proc = proc_open(
            [
                'curl',
                '--fail', '--silent', '--show-error', '--location',
                '--max-time', (string) self::FETCH_TIMEOUT,
                '-H', 'User-Agent: cnkill-updater',
                '-H', 'Accept: application/vnd.github+json',
                self::API_URL,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);

        $raw = stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        return ($exitCode === 0 && $raw !== false && $raw !== '') ? $raw : null;
    }

    /**
     * Perform the GitHub API request using PHP's file_get_contents stream wrapper.
     * Requires allow_url_fopen = 1 and the openssl extension (HTTPS support).
     * Returns the raw response body, or null on failure.
     */
    private function fetchViaFileGetContents(): ?string
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

        return ($raw !== false && $raw !== '') ? $raw : null;
    }

    /**
     * Return the latest release tag, preferring the on-disk cache when fresh.
     * Falls back to a live GitHub fetch when stale or missing.
     */
    public function getLatest(): ?string
    {
        return $this->getCachedLatest() ?? $this->fetchLatest();
    }

    // -------------------------------------------------------------------------
    // Version comparison
    // -------------------------------------------------------------------------

    /**
     * Return true when $candidate is a newer version than $current.
     */
    public function isNewer(string $candidate, string $current): bool
    {
        return version_compare(ltrim($candidate, 'v'), ltrim($current, 'v'), '>');
    }

    // -------------------------------------------------------------------------
    // Install method detection
    // -------------------------------------------------------------------------

    /**
     * Return the real path of the currently running binary.
     */
    public function getCurrentBinaryPath(): string
    {
        $path = $_SERVER['argv'][0] ?? '';

        return (string) (realpath($path) ?: $path);
    }

    /**
     * Return true when cnkill was installed via `composer global require`.
     *
     * Detection relies on the resolved binary path containing the Composer
     * vendor segment for this package.
     */
    public function isComposerInstall(): bool
    {
        $needle = implode(DIRECTORY_SEPARATOR, ['vendor', 'abdelhamiderrahmouni', 'cnkill']);

        return str_contains($this->getCurrentBinaryPath(), $needle);
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

        return 'https://github.com/' . self::REPO . "/releases/download/{$tag}/cnkill-{$os}-{$arch}";
    }
}
