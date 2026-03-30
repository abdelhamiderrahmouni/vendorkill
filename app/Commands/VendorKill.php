<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Spatie\Async\Pool;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\multiselect;

class VendorKill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process {path? : The path to search for vendor directories}
                                    {--maxdepth= : The maximum depth to search for vendor directories}
                                    {--full : Show full details}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete composer vendor directories.';

    /**
     * State array tracking each vendor directory's info.
     * Keys are directory paths, values are ['project' => string, 'size' => int|null].
     * null size means "still calculating".
     *
     * @var array<string, array{project: string, size: int|null}>
     */
    protected array $state = [];

    /**
     * Number of lines currently rendered for the list (used for cursor rewind).
     */
    protected int $renderedLines = 0;

    public function handle(): int
    {
        $searchPath = $this->argument('path') ?? getcwd();

        // Phase 1: Find all vendor directories
        $this->info("Searching for vendor directories in $searchPath...");
        $vendorDirs = $this->findVendorDirs($searchPath);

        if (empty($vendorDirs)) {
            $this->newLine();
            $this->info('No composer vendor directories found in this path.');
            $this->thanks();

            return 0;
        }

        // Build initial state with null sizes (= calculating)
        foreach ($vendorDirs as $dir) {
            $this->state[$dir] = [
                'project' => basename(dirname($dir)),
                'size' => null,
            ];
        }

        $this->newLine();
        $this->renderList();

        // Phase 2: Calculate sizes asynchronously
        $pool = Pool::create()
            ->concurrency(10)
            ->timeout(300);

        foreach ($vendorDirs as $dir) {
            $pool->add(function () use ($dir) {
                $output = trim((string) shell_exec('du -s ' . escapeshellarg($dir) . ' 2>/dev/null | cut -f1'));

                return ['dir' => $dir, 'size' => (int) $output];
            })->then(function (array $result) {
                $this->state[$result['dir']]['size'] = $result['size'];
            })->catch(function () use ($dir) {
                // On error, mark size as 0 so it doesn't stay "calculating" forever
                $this->state[$dir]['size'] = 0;
            });
        }

        // Wait for all size calculations, re-rendering on each loop iteration
        $pool->wait(function () {
            $this->reRenderList();

            return false; // keep waiting
        });

        // Final render with all sizes resolved
        $this->reRenderList();

        // Show total
        $this->showTotal();

        // Phase 3: Prompt user to select directories to delete
        $selectOptions = $this->buildSelectOptions();

        $selectedKeys = multiselect(
            label: 'Choose the directories to delete',
            options: $selectOptions,
            hint: 'Press <fg=green;options=bold>Space</> to choose single/multiple project(s) then press <fg=green;options=bold>Enter</> to confirm'
        );

        // Delete selected directories
        foreach ($selectedKeys as $dir) {
            $process = new Process(['rm', '-rf', $dir]);
            $process->run();
            $this->info("Deleted $dir");
        }

        $this->thanks();

        return 0;
    }

    /**
     * Find vendor directories, skipping vendor/ and node_modules/ recursion.
     *
     * @return string[]
     */
    protected function findVendorDirs(string $searchPath): array
    {
        $maxdepth = $this->option('maxdepth');

        $args = ['find', $searchPath];

        if ($maxdepth !== null) {
            $args[] = '-maxdepth';
            $args[] = $maxdepth;
        }

        // Skip node_modules entirely; for vendor: prune (don't descend) but still print
        $args = array_merge($args, [
            '(',
            '-name', 'node_modules',
            '-prune',
            ')',
            '-o',
            '(',
            '-type', 'd',
            '-name', 'vendor',
            '-print',
            ')',
        ]);

        $process = new Process($args);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return [];
        }

        $vendorDirs = explode(PHP_EOL, $output);

        // Filter: only keep vendor dirs whose parent has a composer.json
        return array_values(array_filter($vendorDirs, function (string $dir) {
            $parentDir = dirname($dir);

            return file_exists($parentDir . DIRECTORY_SEPARATOR . 'composer.json');
        }));
    }

    /**
     * Render the initial list (no cursor rewind).
     */
    protected function renderList(): void
    {
        $this->renderedLines = 0;
        $this->writeListLines();
    }

    /**
     * Re-render the list by rewinding the cursor and overwriting.
     */
    protected function reRenderList(): void
    {
        if ($this->renderedLines > 0) {
            // Move cursor up and clear each line
            $this->output->write(sprintf("\033[%dA", $this->renderedLines));
        }

        $this->renderedLines = 0;
        $this->writeListLines();
    }

    /**
     * Write the list lines to output, tracking line count.
     */
    protected function writeListLines(): void
    {
        $counter = 1;
        $totalSize = 0;
        $allResolved = true;

        foreach ($this->state as $dir => $info) {
            if ($info['size'] !== null) {
                $sizeStr = '<fg=yellow>' . $this->formatSize($info['size']) . '</>';
                $totalSize += $info['size'];
            } else {
                $sizeStr = '<fg=gray>calculating...</>';
                $allResolved = false;
            }

            $this->line(sprintf("\033[K  <options=bold>%d:</> %-40s %s", $counter, $info['project'], $sizeStr));
            $this->renderedLines++;

            if ($this->option('full')) {
                $this->line("\033[K     <fg=gray>$dir</>");
                $this->renderedLines++;
            }

            $counter++;
        }

        // Summary line
        $totalStr = $allResolved
            ? '<fg=green;options=bold>' . $this->formatSize($totalSize) . '</>'
            : '<fg=gray>' . $this->formatSize($totalSize) . ' (calculating...)</>';

        $this->line(sprintf(
            "\033[K  Found <fg=green;options=bold>%d</> vendor %s — Total: %s",
            count($this->state),
            count($this->state) === 1 ? 'directory' : 'directories',
            $totalStr
        ));
        $this->renderedLines++;
    }

    /**
     * Show the final total after all sizes are resolved.
     */
    protected function showTotal(): void
    {
        $totalSize = (int) array_sum(array_column($this->state, 'size'));

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Found ' . count($this->state) . ' vendor directories</>',
            '<fg=green;options=bold>' . $this->formatSize($totalSize) . '</>'
        );
        $this->newLine();
    }

    /**
     * Build options for the multiselect prompt.
     * Keys are directory paths for easy deletion lookup.
     *
     * @return array<string, string>
     */
    protected function buildSelectOptions(): array
    {
        $options = [];

        foreach ($this->state as $dir => $info) {
            $size = $info['size'] !== null ? $this->formatSize($info['size']) : 'unknown';
            $options[$dir] = "{$info['project']} ($size) [$dir]";
        }

        return $options;
    }

    /**
     * Format a size in KB to a human-readable string.
     */
    protected function formatSize(int|float $size): string
    {
        $units = ['KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    protected function thanks(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Thanks for using VendorKill!</>');
    }
}
