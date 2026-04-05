<?php

declare(strict_types=1);

namespace App\Providers;

use App\Prompts\DirectoryListPrompt;
use App\Prompts\Renderers\DirectoryListRenderer;
use App\Services\ConfigService;
use App\Services\VersionChecker;
use Illuminate\Support\ServiceProvider;
use Laravel\Prompts\Prompt;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPromptTheme();
        $this->registerPromptCancelHandler();

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
        $this->app->singleton(ConfigService::class);
    }

    // -------------------------------------------------------------------------
    // Prompt theme
    // -------------------------------------------------------------------------

    /**
     * Register the custom DirectoryListPrompt renderer under the 'cnkill' theme,
     * then activate it. All built-in prompt renderers fall back to 'default'
     * automatically via Prompt::getRenderer()'s fallback chain.
     */
    private function registerPromptTheme(): void
    {
        Prompt::addTheme('cnkill', [
            DirectoryListPrompt::class => DirectoryListRenderer::class,
        ]);

        Prompt::theme('cnkill');
    }

    // -------------------------------------------------------------------------
    // Prompt cancel handler
    // -------------------------------------------------------------------------

    /**
     * Register a global cancel handler so that Ctrl-C (and programmatic
     * cancellations triggered by pressing 'q') exit cleanly with code 0
     * instead of the default exit(1).
     */
    private function registerPromptCancelHandler(): void
    {
        Prompt::cancelUsing(function (): never {
            exit(0);
        });
    }

    // -------------------------------------------------------------------------
    // Upgrade notice
    // -------------------------------------------------------------------------

    /**
     * Print a one-line upgrade hint when a newer version is available.
     *
     * Skipped when the user is running `upgrade` itself. Uses a 24-hour
     * on-disk cache to avoid hitting the GitHub API on every invocation.
     * Network failures are silently swallowed — the notice is best-effort.
     * fwrite(STDOUT) is used directly to guarantee output after Artisan teardown.
     */
    private function maybeShowUpgradeNotice(): void
    {
        if (($_SERVER['argv'][1] ?? null) === 'upgrade') {
            return;
        }

        /** @var VersionChecker $checker */
        $checker = $this->app->make(VersionChecker::class);

        $latest = $checker->getLatest();

        if ($latest === null) {
            return;
        }

        if (! $checker->isNewer($latest, $this->app->version())) {
            return;
        }

        fwrite(STDOUT, "\n  \033[33mNew version available: {$latest}\033[0m  —  run: \033[36mcnkill upgrade\033[0m\n\n");
    }
}
