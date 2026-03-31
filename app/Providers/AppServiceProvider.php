<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\VersionChecker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Show a one-line upgrade notice at the end of every command run
        // (except when the user is already running `cnkill upgrade`).
        $this->app->terminating(function (): void {
            $this->maybeShowUpgradeNotice();
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VersionChecker::class);
    }

    // -------------------------------------------------------------------------
    // Upgrade notice
    // -------------------------------------------------------------------------

    /**
     * Print a one-line upgrade hint when a newer version is available.
     *
     * Strategy:
     *  - Always skipped when the running command is `upgrade` (avoids recursion /
     *    noise after the upgrade command itself prints version info).
     *  - Reads from the on-disk cache (XDG_CACHE_HOME/cnkill/version_check.json).
     *  - If the cache is stale or missing, performs a live GitHub API fetch
     *    (max 3 s timeout) and writes the result to cache. The fetch only
     *    happens at most once every 24 hours, so the overhead is rare.
     *  - Any network failure is silently swallowed — the notice is best-effort.
     */
    private function maybeShowUpgradeNotice(): void
    {
        // Skip when the user is running the upgrade command itself.
        $argv = $_SERVER['argv'] ?? [];

        foreach ($argv as $arg) {
            if ($arg === 'upgrade') {
                return;
            }
        }

        /** @var VersionChecker $checker */
        $checker = $this->app->make(VersionChecker::class);

        // Obtain the latest version.  getLatest() uses the cache when fresh
        // and falls back to a live fetch (with timeout) when stale.
        $latest = $checker->getLatest();

        if ($latest === null) {
            return;
        }

        $current = $this->app->version();

        if (! $checker->isNewer($latest, $current)) {
            return;
        }

        // Print the notice.  fwrite(STDOUT) is used directly so the output
        // appears even if the Artisan output instance has already been torn down.
        fwrite(STDOUT, "\n  \033[33mNew version available: {$latest}\033[0m  —  run: \033[36mcnkill upgrade\033[0m\n\n");
    }
}
