<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\VersionChecker;
use LaravelZero\Framework\Commands\Command;

class UpgradeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upgrade
                            {--check  : Check for a newer version without upgrading}
                            {--force  : Upgrade even when already on the latest version}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upgrade cnkill to the latest version.';

    /**
     * Execute the console command.
     */
    public function handle(VersionChecker $checker): int
    {
        $current = $this->app->version();

        $this->newLine();
        $this->line('  <options=bold>cnkill</> version check');
        $this->line("  Current version: <fg=cyan>{$current}</>");
        $this->newLine();

        // ── Fetch latest release ──────────────────────────────────────────────
        $this->output->write('  Fetching latest release… ');

        $latest = $checker->getLatest();

        if ($latest === null) {
            $this->line('<fg=red>failed</>');
            $this->newLine();
            $this->line('  <fg=red>Could not reach GitHub. Check your internet connection and try again.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line("<fg=green>{$latest}</>");
        $this->newLine();

        // ── --check: report and exit ──────────────────────────────────────────
        if ($this->option('check')) {
            if ($checker->isNewer($latest, $current)) {
                $this->line("  <fg=yellow>A new version is available:</> <options=bold>{$latest}</>");
                $this->line('  Run <fg=cyan>cnkill upgrade</> to install it.');
            } elseif ($checker->isNewer($current, $latest)) {
                $this->line("  <fg=yellow>You are running a pre-release version</> <options=bold>{$current}</>, ahead of the latest stable release <options=bold>{$latest}</>.");
            } else {
                $this->line('  <fg=green>You are on the latest version.</>');
            }

            $this->newLine();

            return self::SUCCESS;
        }

        // ── Already up to date ────────────────────────────────────────────────
        if (! $checker->isNewer($latest, $current) && ! $this->option('force')) {
            $this->line("  <fg=green>Already on the latest version ({$latest}). Nothing to do.</>");
            $this->line('  Use <fg=cyan>--force</> to reinstall the current release.');
            $this->newLine();

            return self::SUCCESS;
        }

        // ── Detect install method and upgrade ─────────────────────────────────
        if ($checker->isComposerInstall()) {
            return $this->upgradeComposer($latest);
        }

        return $this->upgradeStandalone($checker, $latest, $current);
    }

    // -------------------------------------------------------------------------
    // Composer upgrade
    // -------------------------------------------------------------------------

    /**
     * Upgrade by delegating to `composer global update`.
     */
    private function upgradeComposer(string $latest): int
    {
        $this->line('  <options=bold>Install method:</> Composer global');
        $this->newLine();

        $composer = $this->findComposer();

        if ($composer === null) {
            $this->line('  <fg=red>composer not found in PATH.</>');
            $this->line('  Run manually: <fg=cyan>composer global update abdelhamiderrahmouni/cnkill</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line("  Running: <fg=cyan>{$composer} global update abdelhamiderrahmouni/cnkill</>");
        $this->newLine();

        $proc = proc_open(
            [$composer, 'global', 'update', 'abdelhamiderrahmouni/cnkill'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($proc)) {
            $this->line('  <fg=red>Failed to start composer.</>');
            $this->newLine();

            return self::FAILURE;
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $out = fgets($pipes[1]);
            $err = fgets($pipes[2]);

            if ($out !== false) {
                $this->output->write('  ' . $out);
            }

            if ($err !== false) {
                $this->output->write('  ' . $err);
            }

            if (! proc_get_status($proc)['running']) {
                break;
            }

            usleep(20000);
        }

        // Drain any remaining buffered output
        while (($out = fgets($pipes[1])) !== false) {
            $this->output->write('  ' . $out);
        }

        while (($err = fgets($pipes[2])) !== false) {
            $this->output->write('  ' . $err);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        $this->newLine();

        if ($exitCode !== 0) {
            $this->line("  <fg=red>Upgrade failed (composer exited with code {$exitCode}).</>");
            $this->newLine();

            return self::FAILURE;
        }

        $this->line("  <fg=green;options=bold>Upgraded to {$latest} successfully.</>");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Find the path of the `composer` executable.
     * Returns null when it cannot be found.
     */
    private function findComposer(): ?string
    {
        foreach (['composer', 'composer.phar'] as $name) {
            $path = trim((string) shell_exec("command -v {$name} 2>/dev/null"));

            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Standalone binary upgrade
    // -------------------------------------------------------------------------

    /**
     * Upgrade the standalone binary by downloading and atomically replacing it.
     */
    private function upgradeStandalone(VersionChecker $checker, string $latest, string $current): int
    {
        $binaryPath = $checker->getCurrentBinaryPath();

        $this->line('  <options=bold>Install method:</> standalone binary');
        $this->line("  Binary path:    <fg=gray>{$binaryPath}</>");
        $this->newLine();

        // ── Permission check ──────────────────────────────────────────────────
        if (! is_writable($binaryPath)) {
            $this->line("  <fg=red>Cannot write to:</> {$binaryPath}");
            $this->newLine();
            $this->line('  The binary is in a location you do not have write access to.');
            $this->line('  Re-run with elevated privileges, for example:');
            $this->line('  <fg=cyan>  sudo cnkill upgrade</>');
            $this->newLine();

            return self::FAILURE;
        }

        // ── Platform detection ────────────────────────────────────────────────
        $downloadUrl = $checker->getDownloadUrl($latest);

        if ($downloadUrl === null) {
            $this->line('  <fg=red>Unsupported platform:</> ' . PHP_OS_FAMILY . ' / ' . php_uname('m'));
            $this->line('  Supported: Linux x86_64, Linux aarch64, macOS x86_64, macOS aarch64.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line("  Downloading: <fg=gray>{$downloadUrl}</>");
        $this->newLine();

        // ── Download to temp file ─────────────────────────────────────────────
        $tmpFile = tempnam(sys_get_temp_dir(), 'cnkill_');

        if ($tmpFile === false) {
            $this->line('  <fg=red>Failed to create a temporary file.</>');
            $this->newLine();

            return self::FAILURE;
        }

        register_shutdown_function(fn () => @unlink($tmpFile));

        $curlAvailable = trim((string) shell_exec('command -v curl 2>/dev/null')) !== '';

        $downloadExitCode = $curlAvailable
            ? $this->downloadWithCurl($downloadUrl, $tmpFile)
            : $this->downloadWithPhp($downloadUrl, $tmpFile);

        if ($downloadExitCode !== 0) {
            $this->line('  <fg=red>Download failed. Check your internet connection and try again.</>');
            $this->newLine();
            @unlink($tmpFile);

            return self::FAILURE;
        }

        // ── Validate download ─────────────────────────────────────────────────
        if (! file_exists($tmpFile) || filesize($tmpFile) < 1024) {
            $this->line('  <fg=red>Downloaded file appears incomplete or corrupt.</>');
            $this->newLine();
            @unlink($tmpFile);

            return self::FAILURE;
        }

        // ── Atomic replace ────────────────────────────────────────────────────
        if (! @chmod($tmpFile, 0755)) {
            $this->line('  <fg=red>Failed to set executable permission on the downloaded binary.</>');
            $this->newLine();
            @unlink($tmpFile);

            return self::FAILURE;
        }

        if (! @rename($tmpFile, $binaryPath)) {
            // rename() can fail across filesystems — fall back to copy + unlink
            if (! @copy($tmpFile, $binaryPath)) {
                $this->line('  <fg=red>Failed to replace the binary at:</> ' . $binaryPath);
                $this->newLine();
                @unlink($tmpFile);

                return self::FAILURE;
            }

            @chmod($binaryPath, 0755);
            @unlink($tmpFile);
        }

        $this->line("  <fg=green;options=bold>Upgraded successfully:</> {$current} → {$latest}");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Download a URL to a local file using the system `curl` binary.
     * Returns the process exit code (0 = success).
     */
    private function downloadWithCurl(string $url, string $destPath): int
    {
        $proc = proc_open(
            ['curl', '--fail', '--silent', '--show-error', '--location', '-o', $destPath, $url],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($proc)) {
            return 1;
        }

        fclose($pipes[0]);

        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $frameCount = count($frames);
        $frame = 0;

        while (true) {
            $this->output->write("\r  " . $frames[$frame % $frameCount] . ' Downloading…');
            $frame++;

            if (! proc_get_status($proc)['running']) {
                break;
            }

            usleep(80000);
        }

        $this->output->write("\r\033[K");

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }

    /**
     * Download a URL to a local file using PHP's file_get_contents.
     * Used as a fallback when curl is not available.
     * Returns 0 on success, 1 on failure.
     */
    private function downloadWithPhp(string $url, string $destPath): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: cnkill-updater',
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 60,
            ],
        ]);

        $this->output->write('  Downloading…');

        $data = @file_get_contents($url, false, $context);

        $this->output->write("\r\033[K");

        if ($data === false) {
            return 1;
        }

        return file_put_contents($destPath, $data) !== false ? 0 : 1;
    }
}
