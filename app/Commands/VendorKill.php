<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

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
     * Keys are directory paths.
     *
     * Status values:
     *   'calculating' — size not yet known
     *   'ready'       — size known, awaiting user action
     *   'deleting'    — rm -rf in progress
     *   'deleted'     — rm -rf completed
     *
     * @var array<string, array{project: string, size: int|null, status: string}>
     */
    protected array $state = [];

    /**
     * Ordered list of vendor directory paths (keys into $state).
     *
     * @var string[]
     */
    protected array $dirs = [];

    /**
     * Background proc_open handles for size calculations.
     * Maps dir path => ['proc' => resource, 'pipe' => resource]
     *
     * @var array<string, array{proc: resource, pipe: resource}>
     */
    protected array $sizeProcs = [];

    /**
     * Background proc_open handles for deletions.
     * Maps dir path => resource (proc handle)
     *
     * @var array<string, resource>
     */
    protected array $deleteProcs = [];

    /**
     * Index of the currently highlighted row.
     */
    protected int $cursor = 0;

    /**
     * First visible row index (for scrolling).
     */
    protected int $scrollOffset = 0;

    /**
     * Number of list rows visible at once (clamped to actual count).
     */
    protected int $visibleRows = 20;

    /**
     * Number of lines currently rendered (for cursor rewind).
     */
    protected int $renderedLines = 0;

    /**
     * Original stty settings so we can restore them on exit.
     */
    protected string $sttyOriginal = '';

    /**
     * Whether the main loop should keep running.
     */
    protected bool $running = true;

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

        // Build initial state
        foreach ($vendorDirs as $dir) {
            $this->dirs[] = $dir;
            $this->state[$dir] = [
                'project' => basename(dirname($dir)),
                'size' => null,
                'status' => 'calculating',
            ];
        }

        // Clamp visible rows to actual count
        $this->visibleRows = min($this->visibleRows, count($this->dirs));

        // Phase 2: Launch background du -s processes for all dirs
        $this->launchSizeProcesses($vendorDirs);

        // Phase 3: Enter raw terminal mode and run the interactive loop
        $this->enableRawMode();

        $this->newLine();
        $this->printHelp();
        $this->renderList();

        try {
            while ($this->running) {
                // Poll size processes
                $this->pollSizeProcesses();

                // Poll deletion processes
                $this->pollDeleteProcesses();

                // Read keyboard input (non-blocking)
                $this->handleInput();

                // Re-render
                $this->reRenderList();

                usleep(30000); // ~33 fps
            }
        } finally {
            $this->disableRawMode();
            $this->cleanupProcesses();
        }

        $this->newLine();
        $this->showSummary();
        $this->thanks();

        return 0;
    }

    /**
     * Launch one background `du -s` process per directory.
     *
     * @param  string[]  $dirs
     */
    protected function launchSizeProcesses(array $dirs): void
    {
        $concurrency = 10;
        $pending = $dirs;

        // Launch up to $concurrency processes immediately
        $toStart = array_splice($pending, 0, $concurrency);

        foreach ($toStart as $dir) {
            $this->startSizeProcess($dir);
        }

        // Store the rest to launch as slots free up
        // (we'll handle this in pollSizeProcesses via a queue)
        $this->sizePending = $pending;
    }

    /**
     * Pending dirs not yet started (overflow from concurrency limit).
     *
     * @var string[]
     */
    protected array $sizePending = [];

    /**
     * Start a background du -s process for one directory.
     */
    protected function startSizeProcess(string $dir): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin (unused)
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr (discard)
        ];

        $proc = proc_open(
            ['du', '-s', $dir],
            $descriptors,
            $pipes
        );

        if (! is_resource($proc)) {
            // Fallback: mark as 0 immediately
            $this->state[$dir]['size'] = 0;
            $this->state[$dir]['status'] = 'ready';

            return;
        }

        // Make stdout non-blocking
        stream_set_blocking($pipes[1], false);
        fclose($pipes[0]);
        fclose($pipes[2]);

        $this->sizeProcs[$dir] = ['proc' => $proc, 'pipe' => $pipes[1]];
    }

    /**
     * Poll all in-flight size processes; harvest completed ones.
     */
    protected function pollSizeProcesses(): void
    {
        foreach ($this->sizeProcs as $dir => $entry) {
            $status = proc_get_status($entry['proc']);

            if ($status['running']) {
                continue;
            }

            // Process finished — read its output
            $raw = stream_get_contents($entry['pipe']);
            $output = trim((string) $raw);
            $size = $output !== '' ? (int) explode("\t", $output)[0] : 0;

            $this->state[$dir]['size'] = $size;
            $this->state[$dir]['status'] = 'ready';

            fclose($entry['pipe']);
            proc_close($entry['proc']);
            unset($this->sizeProcs[$dir]);

            // Start a pending process if any
            if (! empty($this->sizePending)) {
                $next = array_shift($this->sizePending);
                $this->startSizeProcess($next);
            }
        }
    }

    /**
     * Launch a background rm -rf process for one directory.
     */
    protected function startDeleteProcess(string $dir): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            ['rm', '-rf', $dir],
            $descriptors,
            $pipes
        );

        if (! is_resource($proc)) {
            $this->state[$dir]['status'] = 'deleted';

            return;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->deleteProcs[$dir] = $proc;
    }

    /**
     * Poll all in-flight deletion processes; harvest completed ones.
     */
    protected function pollDeleteProcesses(): void
    {
        foreach ($this->deleteProcs as $dir => $proc) {
            $status = proc_get_status($proc);

            if ($status['running']) {
                continue;
            }

            proc_close($proc);
            unset($this->deleteProcs[$dir]);

            $this->state[$dir]['status'] = 'deleted';
        }
    }

    /**
     * Kill and close any still-running background processes on exit.
     */
    protected function cleanupProcesses(): void
    {
        foreach ($this->sizeProcs as $entry) {
            fclose($entry['pipe']);
            proc_terminate($entry['proc']);
            proc_close($entry['proc']);
        }

        foreach ($this->deleteProcs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }

        $this->sizeProcs = [];
        $this->deleteProcs = [];
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

        return array_values(array_filter($vendorDirs, function (string $dir) {
            $parentDir = dirname($dir);

            return file_exists($parentDir . DIRECTORY_SEPARATOR . 'composer.json');
        }));
    }

    /**
     * Read a single keypress (non-blocking) and handle it.
     */
    protected function handleInput(): void
    {
        $byte = @fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return;
        }

        $count = count($this->dirs);

        if ($byte === "\033") {
            // Escape sequence — read more bytes
            $seq = @fread(STDIN, 2);
            if ($seq === '[A') {
                // Up arrow
                if ($this->cursor > 0) {
                    $this->cursor--;
                    if ($this->cursor < $this->scrollOffset) {
                        $this->scrollOffset = $this->cursor;
                    }
                }
            } elseif ($seq === '[B') {
                // Down arrow
                if ($this->cursor < $count - 1) {
                    $this->cursor++;
                    if ($this->cursor >= $this->scrollOffset + $this->visibleRows) {
                        $this->scrollOffset = $this->cursor - $this->visibleRows + 1;
                    }
                }
            }
        } elseif ($byte === ' ') {
            // Space — delete item under cursor
            $dir = $this->dirs[$this->cursor];
            $status = $this->state[$dir]['status'];

            if ($status === 'ready') {
                $this->state[$dir]['status'] = 'deleting';
                $this->startDeleteProcess($dir);
            }
        } elseif ($byte === 'q' || $byte === "\x03" || $byte === "\x04") {
            // q, Ctrl-C, or Ctrl-D — quit
            $this->running = false;
        }
    }

    /**
     * Print one-line help above the list.
     */
    protected function printHelp(): void
    {
        $this->line('  <fg=gray>↑↓ navigate   <fg=green>space</> delete   <fg=red>q</> quit</>');
        $this->newLine();
    }

    /**
     * Render the list for the first time (no cursor rewind).
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
            $this->output->write(sprintf("\033[%dA", $this->renderedLines));
        }
        $this->renderedLines = 0;
        $this->writeListLines();
    }

    /**
     * Write visible list rows plus the status bar to output.
     */
    protected function writeListLines(): void
    {
        $count = count($this->dirs);
        $totalSize = 0;
        $allResolved = true;
        $deletedCount = 0;

        // Accumulate totals across all dirs (not just visible ones)
        foreach ($this->state as $info) {
            if ($info['size'] !== null) {
                $totalSize += $info['size'];
            }
            if ($info['status'] === 'calculating') {
                $allResolved = false;
            }
            if ($info['status'] === 'deleted') {
                $deletedCount++;
            }
        }

        $visibleEnd = min($this->scrollOffset + $this->visibleRows, $count);

        for ($i = $this->scrollOffset; $i < $visibleEnd; $i++) {
            $dir = $this->dirs[$i];
            $info = $this->state[$dir];
            $isActive = ($i === $this->cursor);

            // Cursor indicator
            $indicator = $isActive ? '<fg=cyan>▶</> ' : '  ';

            // Size / status badge
            switch ($info['status']) {
                case 'calculating':
                    $badge = '<fg=gray>calculating...</>';

                    break;
                case 'ready':
                    $badge = '<fg=yellow>' . $this->formatSize($info['size']) . '</>';

                    break;
                case 'deleting':
                    $badge = '<fg=yellow;options=bold>deleting...</>';

                    break;
                case 'deleted':
                    $badge = '<fg=green;options=bold>deleted ✓</>';

                    break;
                default:
                    $badge = '';
            }

            // Pad the plain project name to align badges, then wrap with tags
            $padded = str_pad($info['project'], 42);
            $displayProject = $info['status'] === 'deleted'
                ? "<fg=gray>{$padded}</>"
                : ($isActive ? "<options=bold;fg=cyan>{$padded}</>" : "<options=bold>{$padded}</>");

            $this->line(sprintf(
                "\033[K %s%s %s",
                $indicator,
                $displayProject,
                $badge
            ));
            $this->renderedLines++;

            if ($this->option('full')) {
                $this->line("\033[K     <fg=gray>$dir</>");
                $this->renderedLines++;
            }
        }

        // Scroll indicator
        if ($count > $this->visibleRows) {
            $this->line(sprintf(
                "\033[K  <fg=gray>%d–%d of %d</>",
                $this->scrollOffset + 1,
                $visibleEnd,
                $count
            ));
            $this->renderedLines++;
        }

        // Status bar
        $totalStr = $allResolved
            ? '<fg=green;options=bold>' . $this->formatSize($totalSize) . '</>'
            : '<fg=gray>' . $this->formatSize($totalSize) . ' (calculating...)</>';

        $deletedStr = $deletedCount > 0
            ? "  <fg=green>{$deletedCount} deleted</>"
            : '';

        $this->line(sprintf(
            "\033[K  Found <fg=green;options=bold>%d</> vendor %s — Total: %s%s",
            $count,
            $count === 1 ? 'directory' : 'directories',
            $totalStr,
            $deletedStr
        ));
        $this->renderedLines++;
    }

    /**
     * Show a final summary after quitting.
     */
    protected function showSummary(): void
    {
        $deleted = array_filter($this->state, fn ($i) => $i['status'] === 'deleted');
        $freedKb = array_sum(array_column($deleted, 'size'));

        if (count($deleted) > 0) {
            $count = count($deleted);
            $this->components->twoColumnDetail(
                "<fg=green;options=bold>Deleted {$count} vendor " . ($count === 1 ? 'directory' : 'directories') . '</>',
                '<fg=green;options=bold>' . $this->formatSize($freedKb) . ' freed</>'
            );
            $this->newLine();
        }
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

    /**
     * Put the terminal into raw mode (no echo, no line buffering, non-blocking reads).
     */
    protected function enableRawMode(): void
    {
        $this->sttyOriginal = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo -icanon min 0 time 0 2>/dev/null');

        // Hide cursor
        $this->output->write("\033[?25l");

        // Restore terminal on fatal shutdown
        register_shutdown_function(function () {
            $this->disableRawMode();
        });

        // Handle SIGINT gracefully (belt-and-suspenders; Ctrl-C also comes via \x03 in raw mode)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
        }
    }

    /**
     * Restore the terminal to its original state.
     */
    protected function disableRawMode(): void
    {
        // Show cursor
        $this->output->write("\033[?25h");

        if ($this->sttyOriginal !== '') {
            shell_exec('stty ' . escapeshellarg($this->sttyOriginal) . ' 2>/dev/null');
            $this->sttyOriginal = ''; // Prevent double-restore from shutdown handler
        }
    }

    protected function thanks(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Thanks for using VendorKill!</>');
    }
}
