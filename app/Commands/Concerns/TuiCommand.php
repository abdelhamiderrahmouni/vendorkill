<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

trait TuiCommand
{
    /**
     * Active sort mode for the visible list.
     */
    protected string $sortMode = 'default';

    /**
     * Currently selected directory path in the visible list.
     */
    protected ?string $activeDir = null;

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

    protected function runTuiLoop(callable $poll, ?callable $shouldStop = null): void
    {
        $this->enableRawMode();
        $this->printHeader();
        $this->printHelp();
        $this->renderList();

        try {
            while ($this->running) {
                $poll();
                $this->handleInput();

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $this->spinnerFrame = ($this->spinnerFrame + 1) % count($this->spinnerFrames);
                $this->updateVisibleRows();
                $this->reRenderList();

                if ($shouldStop !== null && $shouldStop()) {
                    $this->running = false;
                }

                usleep(50000); // ~20 fps
            }
        } finally {
            $this->disableRawMode();
            $this->cleanupProcesses();
            $this->eraseTui();
        }
    }

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

    protected function cleanupTuiProcesses(): void
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

    protected function handleInput(): void
    {
        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return;
        }

        $visibleDirs = $this->syncCursorState($this->visibleDirs());
        $count = count($visibleDirs);

        if ($byte === "\033") {
            $seq = fread(STDIN, 2);

            if ($seq === '[A') {
                if ($this->cursor > 0) {
                    $this->cursor--;

                    if ($this->cursor < $this->scrollOffset) {
                        $this->scrollOffset = $this->cursor;
                    }

                    $this->activeDir = $visibleDirs[$this->cursor];
                }
            } elseif ($seq === '[B') {
                if ($this->cursor < $count - 1) {
                    $this->cursor++;

                    if ($this->cursor >= $this->scrollOffset + $this->visibleRows) {
                        $this->scrollOffset = $this->cursor - $this->visibleRows + 1;
                    }

                    $this->activeDir = $visibleDirs[$this->cursor];
                }
            } elseif ($seq === '[C') {
                if ($count > 0) {
                    $newOffset = min($this->scrollOffset + $this->visibleRows, max(0, $count - $this->visibleRows));
                    $delta = $newOffset - $this->scrollOffset;
                    $this->scrollOffset = $newOffset;
                    $this->cursor = min($this->cursor + $delta, $count - 1);
                    $this->activeDir = $visibleDirs[$this->cursor];
                }
            } elseif ($seq === '[D') {
                if ($count > 0) {
                    $newOffset = max($this->scrollOffset - $this->visibleRows, 0);
                    $delta = $this->scrollOffset - $newOffset;
                    $this->scrollOffset = $newOffset;
                    $this->cursor = max($this->cursor - $delta, 0);
                    $this->activeDir = $visibleDirs[$this->cursor];
                }
            }
        } elseif ($byte === ' ') {
            if ($count === 0) {
                return;
            }

            $dir = $visibleDirs[$this->cursor];
            $status = $this->state[$dir]['status'];

            if ($status === 'ready') {
                $this->state[$dir]['status'] = 'deleting';
                $this->startDeleteProcess($dir);
            }
        } elseif ($byte === 's' || $byte === 'S') {
            $this->activeDir = $count > 0 ? $visibleDirs[$this->cursor] : null;
            $this->cycleSortMode();
            $this->syncCursorState($this->visibleDirs());
        } elseif ($byte === 'q' || $byte === "\x03" || $byte === "\x04") {
            $this->running = false;
        }
    }

    /**
     * Calculate how many list rows fit in the terminal, leaving room for the
     * help line, blank line, optional scroll indicator, and status bar.
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

        $reserved = 6;
        $this->visibleRows = max(1, intdiv($termHeight - $reserved, $this->listRowHeight()));
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

        $this->line('  <fg=gray>' . $this->headerDescription() . '</>');
        $this->newLine();

        $this->headerLines += 1 + count($art) + 1 + 1;
    }

    protected function printHelp(): void
    {
        $this->line('  <fg=gray>↑↓ move  <fg=blue>←→ page</>  <fg=yellow>s</> sort  <fg=green>space</> delete  <fg=red>q</> quit</>');
        $this->newLine();

        $this->headerLines += 2;
    }

    protected function renderList(): void
    {
        $this->renderedLines = 0;
        $this->writeListLines();
    }

    protected function initializeSortMode(): bool
    {
        $sort = strtolower((string) ($this->option('sort') ?? 'default'));

        if (! in_array($sort, $this->availableSortModes(), true)) {
            $this->error('--sort must be one of: ' . implode(', ', $this->availableSortModes()) . '.');

            return false;
        }

        $this->sortMode = $sort;

        return true;
    }

    /**
     * @return string[]
     */
    protected function availableSortModes(): array
    {
        return ['default', 'name', 'size', 'modified'];
    }

    protected function cycleSortMode(): void
    {
        $modes = $this->availableSortModes();
        $index = array_search($this->sortMode, $modes, true);

        if ($index === false) {
            $this->sortMode = $modes[0];

            return;
        }

        $this->sortMode = $modes[($index + 1) % count($modes)];
    }

    /**
     * @return string[]
     */
    protected function visibleDirs(): array
    {
        $dirs = $this->dirs;

        usort($dirs, fn (string $left, string $right): int => $this->compareVisibleDirs($left, $right));

        return $dirs;
    }

    /**
     * @param  string[]  $visibleDirs
     * @return string[]
     */
    protected function syncCursorState(array $visibleDirs): array
    {
        $count = count($visibleDirs);

        if ($count === 0) {
            $this->cursor = 0;
            $this->scrollOffset = 0;
            $this->activeDir = null;

            return $visibleDirs;
        }

        if ($this->activeDir !== null) {
            $index = array_search($this->activeDir, $visibleDirs, true);

            if ($index !== false) {
                $this->cursor = $index;
            } else {
                $this->activeDir = null;
            }
        }

        if ($this->activeDir === null) {
            if ($this->cursor >= $count) {
                $this->cursor = $count - 1;
            }

            $this->cursor = max(0, $this->cursor);
            $this->activeDir = $visibleDirs[$this->cursor];
        }

        $maxOffset = max(0, $count - $this->visibleRows);
        if ($this->scrollOffset > $maxOffset) {
            $this->scrollOffset = $maxOffset;
        }

        if ($this->cursor < $this->scrollOffset) {
            $this->scrollOffset = $this->cursor;
        }

        if ($this->cursor >= $this->scrollOffset + $this->visibleRows) {
            $this->scrollOffset = max(0, $this->cursor - $this->visibleRows + 1);
        }

        return $visibleDirs;
    }

    protected function compareVisibleDirs(string $left, string $right): int
    {
        $leftOrder = $this->sortOrderFor($left);
        $rightOrder = $this->sortOrderFor($right);

        return match ($this->sortMode) {
            'name' => $this->compareByName($left, $right, $leftOrder, $rightOrder),
            'size' => $this->compareByNullableDesc($this->sortSizeFor($left), $this->sortSizeFor($right), $leftOrder, $rightOrder),
            'modified' => $this->compareByNullableDesc($this->sortModifiedFor($left), $this->sortModifiedFor($right), $leftOrder, $rightOrder),
            default => $leftOrder <=> $rightOrder,
        };
    }

    protected function sortIndicator(): string
    {
        return '<fg=yellow>Sort by:</> <fg=cyan>' . ucfirst($this->sortMode) . '</>';
    }

    protected function renderSortLine(): string
    {
        return "\033[K  " . $this->sortIndicator();
    }

    protected function compareByName(string $left, string $right, int $leftOrder, int $rightOrder): int
    {
        $cmp = strcasecmp($this->sortNameFor($left), $this->sortNameFor($right));

        if ($cmp !== 0) {
            return $cmp;
        }

        return $leftOrder <=> $rightOrder;
    }

    protected function compareByNullableDesc(?int $left, ?int $right, int $leftOrder, int $rightOrder): int
    {
        if ($left === null && $right === null) {
            return $leftOrder <=> $rightOrder;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        $cmp = $right <=> $left;

        if ($cmp !== 0) {
            return $cmp;
        }

        return $leftOrder <=> $rightOrder;
    }

    protected function sortNameFor(string $dir): string
    {
        $info = $this->state[$dir] ?? [];

        return (string) ($info['project'] ?? $info['label'] ?? basename($dir));
    }

    protected function sortSizeFor(string $dir): ?int
    {
        return $this->state[$dir]['size'] ?? null;
    }

    protected function sortModifiedFor(string $dir): ?int
    {
        return $this->state[$dir]['lastModified'] ?? null;
    }

    protected function sortOrderFor(string $dir): int
    {
        return (int) ($this->state[$dir]['order'] ?? 0);
    }

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

    /**
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

    protected function renderNumber(int $position, int $numWidth, string $status, bool $isActive): string
    {
        $text = str_pad((string) $position, $numWidth, ' ', STR_PAD_LEFT) . '.';

        if ($status === 'deleted') {
            return "<fg=gray>{$text}</>";
        }

        return $isActive ? "<fg=cyan>{$text}</>" : "<fg=gray>{$text}</>";
    }

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

    protected function formatRelativeTime(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'unknown';
        }

        $seconds = time() - $timestamp;

        if ($seconds < 0) {
            $seconds = 0;
        }

        if ($seconds < 10) {
            return 'just now';
        }

        if ($seconds < 60) {
            return $seconds . 's ago';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . 'min ago';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }

        $days = intdiv($hours, 24);
        if ($days < 7) {
            return $days . 'd ago';
        }

        $weeks = intdiv($days, 7);
        if ($weeks < 5) {
            return $weeks . 'w ago';
        }

        $months = intdiv($days, 30);
        if ($months < 12) {
            return $months . 'mo ago';
        }

        $years = intdiv($days, 365);

        return $years . 'y ago';
    }

    protected function listRowHeight(): int
    {
        return 3;
    }

    protected function enableRawMode(): void
    {
        $this->sttyOriginal = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo -icanon min 0 time 0 2>/dev/null');
        $this->rawModeActive = true;

        $this->output->write("\033[?25l");

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
        $this->output->write("\033[?25h");

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

    abstract protected function cleanupProcesses(): void;

    abstract protected function headerDescription(): string;

    abstract protected function writeListLines(): void;
}
