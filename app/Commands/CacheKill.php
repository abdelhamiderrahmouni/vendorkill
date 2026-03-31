<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CacheKill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear package manager caches (npm, pnpm, yarn, bun, composer, cpx).';

    /**
     * State array tracking each cache entry's info.
     * Keys are absolute directory paths.
     *
     * Status values:
     *   'calculating' — size not yet known
     *   'ready'       — size known, awaiting user action
     *   'deleting'    — rm -rf in progress
     *   'deleted'     — rm -rf completed
     *
     * @var array<string, array{label: string, type: string, size: int|null, status: string}>
     */
    protected array $state = [];

    /**
     * Ordered list of cache directory paths (keys into $state).
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
     * Dirs waiting for a free size-calculation slot.
     *
     * @var string[]
     */
    protected array $sizePending = [];

    /**
     * Max parallel du -s processes.
     */
    protected int $sizeConcurrency = 7;

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
     * Number of list rows visible at once (updated each frame from terminal height).
     */
    protected int $visibleRows = 20;

    /**
     * Terminal width in columns (updated each frame).
     */
    protected int $termWidth = 80;

    /**
     * Number of lines currently rendered (for cursor rewind).
     */
    protected int $renderedLines = 0;

    /**
     * Number of lines printed by printHeader() + printHelp() (for eraseTui).
     */
    protected int $headerLines = 0;

    /**
     * Original stty settings so we can restore them on exit.
     */
    protected string $sttyOriginal = '';

    /**
     * Whether raw terminal mode is currently active (prevents double-restore).
     */
    protected bool $rawModeActive = false;

    /**
     * Whether the main loop should keep running.
     */
    protected bool $running = true;

    /**
     * Whether terminal dimensions need to be re-queried (set on SIGWINCH).
     */
    protected bool $termDirty = true;

    /**
     * Spinner frame index.
     */
    protected int $spinnerFrame = 0;

    /** @var string[] */
    protected array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public function handle(): int
    {
        $paths = $this->resolveCachePaths();

        if (empty($paths)) {
            $this->newLine();
            $this->info('No package manager caches found.');
            $this->newLine();
            $this->line('<fg=blue>Thanks for using CNKill!</>');

            return 0;
        }

        // Populate state and enqueue size calculations before entering the TUI
        foreach ($paths as $dir => $entry) {
            $this->dirs[] = $dir;
            $this->state[$dir] = [
                'label' => $entry['label'],
                'type' => $entry['type'],
                'size' => null,
                'status' => 'calculating',
            ];
            $this->enqueueSizeProcess($dir);
        }

        $this->enableRawMode();

        $this->printHeader();
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

                // Dispatch pending signals (e.g. SIGWINCH for terminal resize)
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Advance spinner
                $this->spinnerFrame = ($this->spinnerFrame + 1) % count($this->spinnerFrames);

                // Recalculate visible rows from current terminal height
                $this->updateVisibleRows();

                // Re-render
                $this->reRenderList();

                usleep(50000); // ~20 fps
            }
        } finally {
            $this->disableRawMode();
            $this->cleanupProcesses();
            $this->eraseTui();
        }

        $this->newLine();
        $this->showSummary();
        $this->thanks();

        return 0;
    }

    // -------------------------------------------------------------------------
    // Cache path resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve all known package-manager cache directories.
     * Each tool is queried for its actual configured path; if the query fails
     * or the tool is not installed, a known default is used.
     * Only paths that exist on disk are returned.
     *
     * @return array<string, array{label: string, type: string}>
     */
    protected function resolveCachePaths(): array
    {
        $home = rtrim((string) ($_SERVER['HOME'] ?? (function_exists('posix_getuid')
            ? (posix_getpwuid(posix_getuid())['dir'] ?? '')
            : '')), DIRECTORY_SEPARATOR);

        $xdgCache = rtrim((string) ($_SERVER['XDG_CACHE_HOME'] ?? ''), DIRECTORY_SEPARATOR) ?: $home . '/.cache';

        $candidates = [
            [
                'path' => rtrim((string) shell_exec('npm config get cache 2>/dev/null'), "\n\r") ?: $home . '/.npm',
                'label' => 'npm',
                'type' => 'npm',
            ],
            [
                'path' => rtrim((string) shell_exec('pnpm store path 2>/dev/null'), "\n\r") ?: $home . '/.local/share/pnpm/store',
                'label' => 'pnpm store',
                'type' => 'pnpm-store',
            ],
            [
                'path' => $xdgCache . '/pnpm',
                'label' => 'pnpm cache',
                'type' => 'pnpm-cache',
            ],
            [
                'path' => rtrim((string) shell_exec('yarn cache dir 2>/dev/null'), "\n\r") ?: $xdgCache . '/yarn',
                'label' => 'yarn',
                'type' => 'yarn',
            ],
            [
                'path' => $home . '/.bun/install/cache',
                'label' => 'bun',
                'type' => 'bun',
            ],
            [
                'path' => rtrim((string) shell_exec('composer config cache-dir 2>/dev/null'), "\n\r") ?: $xdgCache . '/composer',
                'label' => 'composer',
                'type' => 'composer',
            ],
            [
                'path' => $home . '/.cpx',
                'label' => 'cpx',
                'type' => 'cpx',
            ],
        ];

        $result = [];

        foreach ($candidates as $entry) {
            $path = $entry['path'];
            if ($path !== '' && $path !== $home && is_dir($path)) {
                $result[$path] = ['label' => $entry['label'], 'type' => $entry['type']];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Size processes
    // -------------------------------------------------------------------------

    /**
     * Enqueue a dir for size calculation, starting immediately if a slot is free.
     */
    protected function enqueueSizeProcess(string $dir): void
    {
        if (count($this->sizeProcs) < $this->sizeConcurrency) {
            $this->startSizeProcess($dir);
        } else {
            $this->sizePending[] = $dir;
        }
    }

    /**
     * Start a background du -sk process for one directory.
     */
    protected function startSizeProcess(string $dir): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(['du', '-sk', $dir], $descriptors, $pipes);

        if (! is_resource($proc)) {
            $this->state[$dir]['size'] = 0;
            $this->state[$dir]['status'] = 'ready';

            return;
        }

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

            $raw = stream_get_contents($entry['pipe']);
            $output = trim((string) $raw);
            $size = $output !== '' ? (int) explode("\t", $output)[0] : 0;

            $this->state[$dir]['size'] = $size;
            $this->state[$dir]['status'] = 'ready';

            fclose($entry['pipe']);
            proc_close($entry['proc']);
            unset($this->sizeProcs[$dir]);

            // Fill the freed slot
            if (! empty($this->sizePending)) {
                $next = array_shift($this->sizePending);
                $this->startSizeProcess($next);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Delete processes
    // -------------------------------------------------------------------------

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

        $proc = proc_open(['rm', '-rf', $dir], $descriptors, $pipes);

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

    // -------------------------------------------------------------------------
    // Cleanup
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    protected function handleInput(): void
    {
        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return;
        }

        $count = count($this->dirs);

        if ($byte === "\033") {
            $seq = fread(STDIN, 2);

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
            } elseif ($seq === '[C') {
                // Right arrow — page forward
                if ($count > 0) {
                    $newOffset = min($this->scrollOffset + $this->visibleRows, max(0, $count - $this->visibleRows));
                    $delta = $newOffset - $this->scrollOffset;
                    $this->scrollOffset = $newOffset;
                    $this->cursor = min($this->cursor + $delta, $count - 1);
                }
            } elseif ($seq === '[D') {
                // Left arrow — page backward
                if ($count > 0) {
                    $newOffset = max($this->scrollOffset - $this->visibleRows, 0);
                    $delta = $this->scrollOffset - $newOffset;
                    $this->scrollOffset = $newOffset;
                    $this->cursor = max($this->cursor - $delta, 0);
                }
            }
        } elseif ($byte === ' ') {
            if ($count === 0) {
                return;
            }

            $dir = $this->dirs[$this->cursor];
            $status = $this->state[$dir]['status'];

            if ($status === 'ready') {
                $this->state[$dir]['status'] = 'deleting';
                $this->startDeleteProcess($dir);
            }
        } elseif ($byte === 'q' || $byte === "\x03" || $byte === "\x04") {
            $this->running = false;
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Calculate how many list rows fit in the terminal, leaving room for the
     * help line, blank line, optional scroll indicator, and status bar.
     * Only re-queries terminal dimensions when $termDirty is true (SIGWINCH received or first run).
     */
    protected function updateVisibleRows(): void
    {
        if (! $this->termDirty) {
            return;
        }

        $termHeight = (int) (shell_exec('tput lines 2>/dev/null') ?? 24);
        $this->termWidth = (int) (shell_exec('tput cols 2>/dev/null') ?? 80);

        if ($termHeight < 5) {
            $termHeight = 24;
        }

        if ($this->termWidth < 40) {
            $this->termWidth = 80;
        }

        // Reserve: 1 help line + 1 blank + 1 status bar + 1 scroll indicator (worst case) + 1 padding
        // Each item occupies 3 terminal lines (name + path + blank), so divide available lines by 3.
        $reserved = 5;
        $this->visibleRows = max(1, intdiv($termHeight - $reserved, 3));

        $this->termDirty = false;
    }

    protected function printHeader(): void
    {
        $art = [
            '   ██████╗███╗   ██╗██╗  ██╗██╗██╗     ██╗     ',
            '  ██╔════╝████╗  ██║██║ ██╔╝██║██║     ██║     ',
            '  ██║     ██╔██╗ ██║█████╔╝ ██║██║     ██║     ',
            '  ██║     ██║╚██╗██║██╔═██╗ ██║██║     ██║     ',
            '  ╚██████╗██║ ╚████║██║  ██╗██║███████╗███████╗',
            '   ╚═════╝╚═╝  ╚═══╝╚═╝  ╚═╝╚═╝╚══════╝╚══════╝',
        ];

        $this->newLine();

        foreach ($art as $line) {
            $this->line('<fg=blue>' . $line . '</>');
        }

        $this->line('  <fg=gray>Clear package manager caches to reclaim disk space.</>');
        $this->newLine();

        // 1 (newLine) + count($art) + 1 (description) + 1 (newLine)
        $this->headerLines += 1 + count($art) + 1 + 1;
    }

    protected function printHelp(): void
    {
        $this->line('  <fg=gray>↑↓ navigate   <fg=blue>←→ page</>  <fg=green>space</> delete   <fg=red>q</> quit</>');
        $this->newLine();

        $this->headerLines += 2; // help line + blank line
    }

    protected function renderList(): void
    {
        $this->renderedLines = 0;
        $this->writeListLines();
    }

    /**
     * Erase the entire TUI from the terminal — list block + the header lines printed before the loop.
     * Called once on exit so the final summary prints on a clean screen.
     */
    protected function eraseTui(): void
    {
        $total = $this->headerLines + $this->renderedLines;

        if ($total > 0) {
            $this->output->write(sprintf("\033[%dA\033[J", $total));
        }
    }

    protected function reRenderList(): void
    {
        if ($this->renderedLines > 0) {
            $this->output->write(sprintf("\033[%dA", $this->renderedLines));
        }

        $this->renderedLines = 0;
        $this->writeListLines();
    }

    protected function writeListLines(): void
    {
        $count = count($this->dirs);
        [$totalSize, $allSized, $deletedCount, $freedSize] = $this->computeStats();

        // Clamp cursor / offset to current list length
        if ($count > 0 && $this->cursor >= $count) {
            $this->cursor = $count - 1;
        }

        if ($this->scrollOffset > max(0, $count - $this->visibleRows)) {
            $this->scrollOffset = max(0, $count - $this->visibleRows);
        }

        $visibleEnd = min($this->scrollOffset + $this->visibleRows, $count);

        $numWidth = mb_strlen((string) $count);

        for ($i = $this->scrollOffset; $i < $visibleEnd; $i++) {
            $dir = $this->dirs[$i];
            $info = $this->state[$dir];
            $isActive = ($i === $this->cursor);

            // prefixLen = 1 (leading space) + 2 (indicator) + numWidth + 1 (dot) + 1 (space)
            $prefixLen = 1 + 2 + $numWidth + 1 + 1;

            $indicator = $isActive ? '<fg=cyan>▶</> ' : '  ';
            $number = $this->renderNumber($i + 1, $numWidth, $info['status'], $isActive);
            [$typeTag, $typeTagText] = $this->renderTypeTag($info['type']);
            [$badge, $badgeText] = $this->renderBadge($info['status'], $info['size']);
            $displayLabel = $this->renderLabel($info['label'], $info['status'], $isActive, $prefixLen, $typeTagText, $badgeText);
            $displayPath = $this->renderPath($dir, $info['status'], $isActive, $prefixLen);

            $this->line(sprintf("\033[K %s%s %s%s%s", $indicator, $number, $displayLabel, $typeTag, $badge));
            $this->renderedLines++;

            $this->line($displayPath);
            $this->renderedLines++;

            $this->line("\033[K");
            $this->renderedLines++;
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
        $this->line($this->buildStatusBar($count, $totalSize, $allSized, $deletedCount, $freedSize));
        $this->renderedLines++;
    }

    /**
     * Aggregate totals from state for the status bar.
     *
     * @return array{int, bool, int, int} [totalSize, allSized, deletedCount, freedSize]
     */
    protected function computeStats(): array
    {
        $totalSize = 0;
        $allSized = true;
        $deletedCount = 0;
        $freedSize = 0;

        foreach ($this->state as $info) {
            if ($info['size'] !== null) {
                $totalSize += $info['size'];
            }

            if ($info['status'] === 'calculating') {
                $allSized = false;
            }

            if ($info['status'] === 'deleted') {
                $deletedCount++;
                $freedSize += (int) $info['size'];
            }
        }

        return [$totalSize, $allSized, $deletedCount, $freedSize];
    }

    /**
     * Render the right-aligned numeric index prefix for a list row.
     */
    protected function renderNumber(int $position, int $numWidth, string $status, bool $isActive): string
    {
        $text = str_pad((string) $position, $numWidth, ' ', STR_PAD_LEFT) . '.';

        if ($status === 'deleted') {
            return "<fg=gray>{$text}</>";
        }

        return $isActive ? "<fg=cyan>{$text}</>" : "<fg=gray>{$text}</>";
    }

    /**
     * Render the type badge for a cache entry. Always shown, color varies by tool.
     * Returns [markup, plainText] — plainText is used for width calculations.
     *
     * @return array{string, string}
     */
    protected function renderTypeTag(string $type): array
    {
        return match ($type) {
            'npm' => [' <fg=red>[npm]</>',         ' [npm]'],
            'pnpm-store' => [' <fg=yellow>[pnpm store]</>', ' [pnpm store]'],
            'pnpm-cache' => [' <fg=yellow>[pnpm cache]</>', ' [pnpm cache]'],
            'yarn' => [' <fg=cyan>[yarn]</>',        ' [yarn]'],
            'bun' => [' <fg=magenta>[bun]</>',      ' [bun]'],
            'composer' => [' <fg=blue>[composer]</>',    ' [composer]'],
            'cpx' => [' <fg=green>[cpx]</>',        ' [cpx]'],
            default => ['', ''],
        };
    }

    /**
     * Render the status badge for a list row.
     * Returns [markup, plainText] — plainText is used for width calculations.
     *
     * @return array{string, string}
     */
    protected function renderBadge(string $status, ?int $size): array
    {
        return match ($status) {
            'calculating' => ['  <fg=gray>calculating...</>', '  calculating...'],
            'ready' => ['  <fg=yellow>' . $this->formatSize($size) . '</>', '  ' . $this->formatSize($size)],
            'deleting' => ['  <fg=yellow;options=bold>deleting...</>', '  deleting...'],
            'deleted' => ['  <fg=green;options=bold>deleted ✓</>', '  deleted ✓'],
            default => ['', ''],
        };
    }

    /**
     * Render the cache label cell, truncated and padded to fill available width.
     */
    protected function renderLabel(string $label, string $status, bool $isActive, int $prefixLen, string $typeTagText, string $badgeText): string
    {
        $rightWidth = mb_strlen($typeTagText) + mb_strlen($badgeText);
        $labelWidth = max(10, $this->termWidth - $prefixLen - $rightWidth);

        $plain = mb_strlen($label) > $labelWidth
            ? mb_substr($label, 0, $labelWidth - 1) . '…'
            : str_pad($label, $labelWidth);

        if ($status === 'deleted') {
            return "<fg=gray>{$plain}</>";
        }

        return $isActive ? "<options=bold;fg=cyan>{$plain}</>" : "<options=bold>{$plain}</>";
    }

    /**
     * Render the path line beneath the label, truncated to fit the terminal width.
     */
    protected function renderPath(string $dir, string $status, bool $isActive, int $prefixLen): string
    {
        $color = ($status === 'deleted' || ! $isActive) ? 'gray' : 'cyan';

        $maxWidth = $this->termWidth - $prefixLen;

        $path = $dir;
        if (mb_strlen($path) > $maxWidth) {
            $path = '…' . mb_substr($path, mb_strlen($path) - $maxWidth + 1);
        }

        return sprintf("\033[K%s<fg=%s>%s</>", str_repeat(' ', $prefixLen), $color, $path);
    }

    protected function buildStatusBar(int $count, int $totalSize, bool $allSized, int $deletedCount, int $freedSize = 0): string
    {
        $cacheWord = $count === 1 ? 'cache' : 'caches';

        $totalStr = $this->buildTotalStr($count, $totalSize, $allSized);
        $deletedStr = $deletedCount > 0
            ? "  <fg=green>{$deletedCount} deleted</> <fg=green;options=bold>(" . $this->formatSize($freedSize) . ' freed)</>'
            : '';

        return sprintf(
            "\033[K  <fg=green;options=bold>%d</> %s  <fg=gray>done</>%s%s",
            $count,
            $cacheWord,
            $totalStr,
            $deletedStr
        );
    }

    protected function buildTotalStr(int $count, int $totalSize, bool $allSized): string
    {
        if ($count === 0) {
            return '';
        }

        if (! $allSized) {
            return '  Total: <fg=gray>' . $this->formatSize($totalSize) . ' …</>';
        }

        return '  Total: <fg=green;options=bold>' . $this->formatSize($totalSize) . '</>';
    }

    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

    protected function showSummary(): void
    {
        $deleted = array_filter($this->state, fn ($i) => $i['status'] === 'deleted');
        $count = count($deleted);

        if ($count === 0) {
            return;
        }

        $cacheWord = $count === 1 ? 'cache' : 'caches';

        $this->components->twoColumnDetail(
            "<fg=green;options=bold>Deleted {$count} {$cacheWord}</>",
            '<fg=green;options=bold>' . $this->formatSize($this->freedSizeKb()) . ' freed</>'
        );
        $this->newLine();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Total size in KB of all deleted cache directories.
     */
    protected function freedSizeKb(): int
    {
        return (int) array_sum(array_column(
            array_filter($this->state, fn ($i) => $i['status'] === 'deleted'),
            'size'
        ));
    }

    protected function formatSize(int|float|null $size): string
    {
        if ($size === null || $size === 0) {
            return '0 KB';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $unitCount = count($units);
        $i = 0;

        while ($size >= 1024 && $i < $unitCount - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    protected function enableRawMode(): void
    {
        $this->sttyOriginal = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo -icanon min 0 time 0 2>/dev/null');
        $this->rawModeActive = true;

        $this->output->write("\033[?25l"); // hide cursor

        register_shutdown_function(function () {
            $this->disableRawMode();
        });

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });

            if (defined('SIGWINCH')) {
                pcntl_signal(SIGWINCH, function () {
                    $this->termDirty = true;
                });
            }
        }
    }

    protected function disableRawMode(): void
    {
        if (! $this->rawModeActive) {
            return;
        }

        $this->rawModeActive = false;
        $this->output->write("\033[?25h"); // show cursor

        if ($this->sttyOriginal !== '') {
            shell_exec('stty ' . escapeshellarg($this->sttyOriginal) . ' 2>/dev/null');
        }
    }

    protected function thanks(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Thanks for using CNKill!</>');

        $freed = $this->freedSizeKb();

        if ($freed > 0) {
            $this->line('<fg=gray>Space released: ' . $this->formatSize($freed) . '</>');
        }
    }
}
