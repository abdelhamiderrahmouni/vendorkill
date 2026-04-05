<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\multiselect;

class ConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure which folder types cnkill scans.';

    public function handle(): int
    {
        /** @var ConfigService $config */
        $config = $this->laravel->make(ConfigService::class);

        $currentlyEnabled = $config->getEnabledTypes();

        // Build the options list: key => display label (with default badge)
        $options = [];
        foreach (ConfigService::FOLDER_TYPES as $key => $type) {
            $badge = $type['default'] ? '' : '  (off by default)';
            $options[$key] = $type['label'] . $badge;
        }

        $this->newLine();
        $this->line('  <options=bold>cnkill config</> — select which folder types to scan.');
        $this->line('  <fg=gray>Use <space> to toggle, <enter> to save.</>');
        $this->newLine();

        $selected = multiselect(
            label: 'Enabled folder types',
            options: $options,
            default: $currentlyEnabled,
            scroll: count($options),
            hint: 'Changes take effect immediately for all future scans.',
        );

        if (! is_array($selected)) {
            $this->newLine();
            $this->line('  <fg=yellow>Cancelled — no changes saved.</>');
            $this->newLine();

            return 0;
        }

        $saved = $config->setEnabledTypes($selected);

        $this->newLine();

        if (! $saved) {
            $this->line('  <fg=red>Failed to write config to: ' . $config->configPath() . '</>');
            $this->newLine();

            return 1;
        }

        $count = count($selected);
        $dirWord = $count === 1 ? 'type' : 'types';
        $this->line("  <fg=green;options=bold>Saved.</> {$count} folder {$dirWord} enabled.");
        $this->line('  <fg=gray>Config stored at: ' . $config->configPath() . '</>');
        $this->newLine();

        if ($count === 0) {
            $this->line('  <fg=yellow>Warning: no folder types enabled — cnkill will not find anything.</>');
            $this->newLine();
        }

        return 0;
    }
}
