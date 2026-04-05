<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config {action? : Action to perform: add, remove (default: show checkbox list)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure which folder types cnkill scans.';

    public function handle(ConfigService $config): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'add' => $this->handleAdd($config),
            'remove' => $this->handleRemove($config),
            null => $this->handleList($config),
            default => $this->handleUnknownAction((string) $action),
        };
    }

    // -------------------------------------------------------------------------
    // cnkill config  (checkbox list of all types)
    // -------------------------------------------------------------------------

    private function handleList(ConfigService $config): int
    {
        $allTypes = $config->getAllTypes();
        $currentlyEnabled = $config->getEnabledTypes();

        // Build the options list: key => display label
        $options = [];
        foreach ($allTypes as $key => $type) {
            $isCustom = str_starts_with($key, 'custom:');
            $suffix = match (true) {
                $isCustom => '  [custom]',
                ! $type['default'] => '  (off by default)',
                default => '',
            };
            $options[$key] = $type['label'] . $suffix;
        }

        $this->newLine();
        $this->line('  <options=bold>cnkill config</> — select which folder types to scan.');
        $this->line('  <fg=gray>Use <space> to toggle, <enter> to save. Run `cnkill config add` to add custom types.</>');
        $this->newLine();

        $selected = multiselect(
            label: 'Enabled folder types',
            options: $options,
            default: $currentlyEnabled,
            scroll: min(count($options), 20),
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

    // -------------------------------------------------------------------------
    // cnkill config add
    // -------------------------------------------------------------------------

    private function handleAdd(ConfigService $config): int
    {

        $this->newLine();
        $this->line('  <options=bold>cnkill config add</> — define a custom folder type to scan.');
        $this->line('  <fg=gray>Press Ctrl-C at any time to cancel.</>');
        $this->newLine();

        // ── 1. Folder name / pattern ─────────────────────────────────────────
        $this->line('  <options=bold>Step 1 of 4 — Folder name</>');
        $this->line('  <fg=gray>Enter a simple name (e.g. <fg=white>.venv</>) or a path pattern');
        $this->line('  <fg=gray>with a leading <fg=white>*/</> for nested paths (e.g. <fg=white>*/ios/build</>).</>');
        $this->newLine();

        $folderInput = text(
            label: 'Folder name or pattern',
            placeholder: '.venv',
            validate: function (string $value): ?string {
                $value = trim($value);

                if ($value === '') {
                    return 'Required.';
                }

                if (str_contains($value, ' ')) {
                    return 'Folder names cannot contain spaces.';
                }

                return null;
            },
            hint: 'Use */subdir/name for nested paths.',
        );

        $folderInput = trim((string) $folderInput);
        $isPattern = str_starts_with($folderInput, '*/');
        $names = $isPattern ? [] : [$folderInput];
        $paths = $isPattern ? [$folderInput] : [];

        // ── 2. Label ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('  <options=bold>Step 2 of 4 — Label</>');
        $this->line('  <fg=gray>This is the human-readable name shown in `cnkill config`.</>');
        $this->newLine();

        $defaultLabel = $folderInput . '  (custom)';
        $label = text(
            label: 'Label',
            default: $defaultLabel,
            placeholder: $defaultLabel,
            hint: 'Press enter to accept the default.',
        );

        $label = trim((string) $label) ?: $defaultLabel;

        // ── 3. Manifest files ─────────────────────────────────────────────────
        $this->newLine();
        $this->line('  <options=bold>Step 3 of 4 — Manifest files</>');
        $this->line('  <fg=gray>cnkill checks the parent directory for one of these files to');
        $this->line('  <fg=gray>confirm it is a real project (not a stray folder).');
        $this->line('  <fg=gray>Enter filenames separated by commas, or leave blank to skip the check.</>');
        $this->newLine();

        $manifestInput = text(
            label: 'Manifest files',
            placeholder: 'package.json, composer.json',
            hint: 'Comma-separated. Leave blank to match any parent directory.',
        );

        $manifests = $this->parseCommaSeparated((string) $manifestInput);

        // ── 4. Lock / reference files for last-modified ───────────────────────
        $this->newLine();
        $this->line('  <options=bold>Step 4 of 4 — Lock / reference files</>');
        $this->line('  <fg=gray>cnkill uses these files to determine when the project was last active');
        $this->line('  <fg=gray>(the most recent mtime is shown in the list).');
        $this->line('  <fg=gray>Defaults to the same list as manifests if left blank.</>');
        $this->newLine();

        $lockfileInput = text(
            label: 'Lock / reference files',
            placeholder: 'package-lock.json, yarn.lock',
            hint: 'Comma-separated. Leave blank to reuse manifest files.',
        );

        $lockfiles = $this->parseCommaSeparated((string) $lockfileInput) ?: $manifests;

        // ── Confirmation ──────────────────────────────────────────────────────
        $this->newLine();
        $this->line('  <options=bold>Summary</>');
        $this->line(sprintf('  Folder:    <fg=cyan>%s</>', $folderInput));
        $this->line(sprintf('  Label:     <fg=white>%s</>', $label));
        $this->line(sprintf('  Manifests: <fg=gray>%s</>', $manifests ? implode(', ', $manifests) : 'none (match all)'));
        $this->line(sprintf('  Lockfiles: <fg=gray>%s</>', $lockfiles ? implode(', ', $lockfiles) : 'none'));
        $this->newLine();

        $confirmed = confirm(label: 'Save this custom type?', default: true);

        if (! $confirmed) {
            $this->newLine();
            $this->line('  <fg=yellow>Cancelled — no changes saved.</>');
            $this->newLine();

            return 0;
        }

        $key = $config->addCustomType([
            'label' => $label,
            'names' => $names,
            'paths' => $paths,
            'manifests' => $manifests,
            'lockfiles' => $lockfiles,
        ]);

        $this->newLine();

        if ($key === null) {
            $this->line('  <fg=red>Failed to write config to: ' . $config->configPath() . '</>');
            $this->newLine();

            return 1;
        }

        $this->line("  <fg=green;options=bold>Saved.</> Custom type <fg=cyan>{$folderInput}</> added and enabled.");
        $this->line('  <fg=gray>Config stored at: ' . $config->configPath() . '</>');
        $this->newLine();

        return 0;
    }

    // -------------------------------------------------------------------------
    // cnkill config remove
    // -------------------------------------------------------------------------

    private function handleRemove(ConfigService $config): int
    {
        $customTypes = $config->getCustomTypes();

        $this->newLine();

        if (empty($customTypes)) {
            $this->line('  <fg=yellow>No custom types defined yet. Run `cnkill config add` to add one.</>');
            $this->newLine();

            return 0;
        }

        $this->line('  <options=bold>cnkill config remove</> — delete a custom folder type.');
        $this->newLine();

        $options = [];
        foreach ($customTypes as $key => $type) {
            $options[$key] = $type['label'];
        }

        $key = select(
            label: 'Which custom type do you want to remove?',
            options: $options,
            scroll: min(count($options), 15),
        );

        $typeLabel = $customTypes[(string) $key]['label'] ?? (string) $key;

        $this->newLine();
        $confirmed = confirm(
            label: "Remove \"{$typeLabel}\"?",
            default: false,
            hint: 'This cannot be undone.',
        );

        if (! $confirmed) {
            $this->newLine();
            $this->line('  <fg=yellow>Cancelled — no changes made.</>');
            $this->newLine();

            return 0;
        }

        $removed = $config->removeCustomType((string) $key);

        $this->newLine();

        if (! $removed) {
            $this->line('  <fg=red>Failed to remove type or write config.</>');
            $this->newLine();

            return 1;
        }

        $this->line("  <fg=green;options=bold>Removed.</> \"{$typeLabel}\" has been deleted.");
        $this->newLine();

        return 0;
    }

    // -------------------------------------------------------------------------
    // Unknown action
    // -------------------------------------------------------------------------

    private function handleUnknownAction(string $action): int
    {
        $this->newLine();
        $this->line("  <fg=red>Unknown action: {$action}</>");
        $this->line('  Available actions: <fg=cyan>cnkill config</>, <fg=cyan>cnkill config add</>, <fg=cyan>cnkill config remove</>');
        $this->newLine();

        return 1;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Split a comma-separated user input string into a clean array of filenames.
     *
     * @return string[]
     */
    private function parseCommaSeparated(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $input)),
            fn (string $s) => $s !== ''
        ));
    }
}
