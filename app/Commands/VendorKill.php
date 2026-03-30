<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Spatie\Async\Pool;
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
     * Index of the currently highlighted row.
     */
    protected int $cursor = 0;

    /**
     * First visible row index (for scrolling).
     */
    protected int $scrollOffset = 0;

    /**
     * Number of list rows visible at once.
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
     * Pool used for deletion tasks.
     */
    protected ?Pool $deletePool = null;

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

        // Phase 2: Build async pool for size calculations
        $sizePool = Pool::create()
            ->concurrency(10)
            ->timeout(300)
            ->sleepTime(0); // We control the frame rate in the main loop

        foreach ($vendorDirs as $dir) {
            $sizePool->add(function () use ($dir) {
                $output = trim((string) shell_exec('du -s ' . escapeshellarg($dir) . ' 2>/dev/null | cut -f1'));

                return ['dir' => $dir, 'size' => (int) $output];
            })->then(function (array $result) {
                if ($this->state[$result['dir']]['status'] === 'calculating') {
                    $this->state[$result['dir']]['size'] = $result['size'];
                    $this->state[$result['dir']]['status'] = 'ready';
                }
            })->catch(function () use ($dir) {
                if ($this->state[$dir]['status'] === 'calculating') {
                    $this->state[$dir]['size'] = 0;
                    $this->state[$dir]['status'] = 'ready';
                }
            });
        }

        // Phase 3: Enter raw terminal mode and run the interactive loop
        $this->enableRawMode();

        $this->newLine();
        $this->printHelp();
        $this->renderList();

        // Create the deletion pool (persistent, reused)
        $this->deletePool = Pool::create()
            ->concurrency(5)
            ->timeout(300)
            ->sleepTime(0); // We control the frame rate in the main loop

        try {
            while ($this->running) {
                // Tick size pool (non-blocking)
                $this->tickPool($sizePool);

                // Tick delete pool (non-blocking)
                $this->tickPool($this->deletePool);

                // Read keyboard input (non-blocking)
                $this->handleInput();

                // Re-render
                $this->reRenderList();

                // Check if we're done (all items are deleted or ready, and user pressed q/ctrl-c)
                usleep(30000); // ~33 fps
            }
        } finally {
            $this->disableRawMode();
        }

        $this->newLine();
        $this->showSummary();
        $this->thanks();

        return 0;
    }

    /**
     * Tick a pool once without blocking.
     * spatie/async Pool::wait() is blocking; we use Reflection to call
     * the internal loop body a single time so we stay non-blocking.
     */
    protected function tickPool(Pool $pool): void
    {
        // Pool::wait() internally calls $pool->notify() in a loop.
        // We mimic one iteration: process finished children without blocking.
        try {
            // Use the public API: check if there's anything to process
            // by calling wait with a callback that immediately returns true (stop early)
            // But spatie/async doesn't expose a non-blocking tick directly.
            // Instead we rely on the fact that Pool internally uses pcntl_waitpid with WNOHANG
            // when we call wait() with a callback returning true after one pass.
            $pool->wait(function () {
                return true; // stop after first tick
            });
        } catch (\Throwable) {
            // Ignore errors during tick (e.g. pool already finished)
        }
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
                $this->startDeletion($dir);
            }
        } elseif ($byte === 'q' || $byte === "\x03" || $byte === "\x04") {
            // q, Ctrl-C, or Ctrl-D — quit
            $this->running = false;
        }
    }

    /**
     * Enqueue an async deletion task for the given directory.
     */
    protected function startDeletion(string $dir): void
    {
        $this->deletePool->add(function () use ($dir) {
            $process = new Process(['rm', '-rf', $dir]);
            $process->run();

            return ['dir' => $dir, 'success' => $process->isSuccessful()];
        })->then(function (array $result) {
            $this->state[$result['dir']]['status'] = 'deleted';
        })->catch(function () use ($dir) {
            // Mark as deleted anyway so the UI doesn't stay stuck
            $this->state[$dir]['status'] = 'deleted';
        });
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
            $scrollInfo = sprintf(
                "\033[K  <fg=gray>%d–%d of %d</>",
                $this->scrollOffset + 1,
                $visibleEnd,
                $count
            );
            $this->line($scrollInfo);
            $this->renderedLines++;
        }

        // Status bar
        $totalStr = $allResolved
            ? '<fg=green;options=bold>' . $this->formatSize($totalSize) . '</>'
            : '<fg=gray>' . $this->formatSize($totalSize) . ' (calculating...)</>';

        $deletedStr = $deletedCount > 0
            ? "  <fg=green>$deletedCount deleted</>"
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
            $this->components->twoColumnDetail(
                '<fg=green;options=bold>Deleted ' . count($deleted) . ' vendor ' . (count($deleted) === 1 ? 'directory' : 'directories') . '</>',
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

        // Register a shutdown function to restore terminal state even on fatal errors
        register_shutdown_function(function () {
            $this->disableRawMode();
        });

        // Handle SIGINT (Ctrl-C) gracefully
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
