<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CnKill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process {path? : The path to search for vendor directories}
                                    {--maxdepth= : The maximum depth to search for vendor directories}
                                    {--node : Search for node_modules directories instead of vendor}
                                    {--all : Search for both vendor and node_modules directories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete composer vendor / node_modules directories.';

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
     * @var array<string, array{project: string, size: int|null, status: string, type: string}>
     */
    protected array $state = [];

    /**
     * The root path being scanned (used to make displayed paths relative).
     */
    protected string $searchPath = '';

    /**
     * Ordered list of vendor directory paths (keys into $state).
     *
     * @var string[]
     */
    protected array $dirs = [];

    /**
     * Background proc_open handle + pipe for the streaming `find` process.
     *
     * @var array{proc: resource, pipe: resource, buf: string}|null
     */
    protected ?array $findProc = null;

    /**
     * Whether the find process has finished.
     */
    protected bool $findDone = false;

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
    protected int $sizeConcurrency = 10;

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
     * Original stty settings so we can restore them on exit.
     */
    protected string $sttyOriginal = '';

    /**
     * Whether the main loop should keep running.
     */
    protected bool $running = true;

    /**
     * Spinner frame index.
     */
    protected int $spinnerFrame = 0;

    /** @var string[] */
    protected array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public function handle(): int
    {
        $searchPath = $this->argument('path') ?? getcwd();
        $this->searchPath = rtrim((string) realpath($searchPath), DIRECTORY_SEPARATOR);

        // Enter raw mode and show the UI immediately
        $this->enableRawMode();

        $this->newLine();
        $this->printHelp();
        $this->renderList();

        // Start the find process in the background (non-blocking)
        $this->startFindProcess($this->searchPath);

        try {
            while ($this->running) {
                // Drain new lines from find's stdout pipe
                $this->pollFindProcess();

                // Poll size processes
                $this->pollSizeProcesses();

                // Poll deletion processes
                $this->pollDeleteProcesses();

                // Read keyboard input (non-blocking)
                $this->handleInput();

                // Advance spinner
                $this->spinnerFrame = ($this->spinnerFrame + 1) % count($this->spinnerFrames);

                // Recalculate visible rows from current terminal height
                $this->updateVisibleRows();

                // Re-render
                $this->reRenderList();

                // Auto-exit when search is done and nothing was found
                if ($this->findDone && empty($this->dirs)) {
                    $this->running = false;
                }

                usleep(50000); // ~20 fps — comfortable for a TUI
            }
        } finally {
            $this->disableRawMode();
            $this->cleanupProcesses();
            $this->eraseTui();
        }

        // If the list is empty after find completes, say so
        if (empty($this->dirs)) {
            $this->newLine();
            $this->info('No ' . $this->targetLabel() . ' directories found in this path.');
        } else {
            $this->newLine();
            $this->showSummary();
        }

        $this->thanks();

        return 0;
    }

    // -------------------------------------------------------------------------
    // Find process
    // -------------------------------------------------------------------------

    /**
     * Launch `find` as a non-blocking background process.
     */
    protected function startFindProcess(string $searchPath): void
    {
        $maxdepth = $this->option('maxdepth');
        $nodeMode = $this->option('node');
        $allMode = $this->option('all');

        $args = ['find', $searchPath];

        if ($maxdepth !== null) {
            $args[] = '-maxdepth';
            $args[] = $maxdepth;
        }

        if ($allMode) {
            // Prune+print both vendor and node_modules
            $args = array_merge($args, [
                '(',
                '-type', 'd',
                '(',
                '-name', 'vendor',
                '-o',
                '-name', 'node_modules',
                ')',
                '-prune',
                '-print',
                ')',
            ]);
        } elseif ($nodeMode) {
            // Prune vendor (don't print), prune+print node_modules
            $args = array_merge($args, [
                '(',
                '-name', 'vendor',
                '-prune',
                ')',
                '-o',
                '(',
                '-type', 'd',
                '-name', 'node_modules',
                '-prune',
                '-print',
                ')',
            ]);
        } else {
            // Default: prune node_modules (don't print), prune+print vendor
            $args = array_merge($args, [
                '(',
                '-name', 'node_modules',
                '-prune',
                ')',
                '-o',
                '(',
                '-type', 'd',
                '-name', 'vendor',
                '-prune',
                '-print',
                ')',
            ]);
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin  (unused)
            1 => ['pipe', 'w'],  // stdout — lines of vendor paths
            2 => ['pipe', 'w'],  // stderr (discard)
        ];

        $proc = proc_open($args, $descriptors, $pipes);

        if (! is_resource($proc)) {
            $this->findDone = true;

            return;
        }

        stream_set_blocking($pipes[1], false);
        fclose($pipes[0]);
        fclose($pipes[2]);

        $this->findProc = ['proc' => $proc, 'pipe' => $pipes[1], 'buf' => ''];
    }

    /**
     * Read whatever is available from find's stdout and register new dirs.
     */
    protected function pollFindProcess(): void
    {
        if ($this->findDone || $this->findProc === null) {
            return;
        }

        // Read available bytes (non-blocking)
        $chunk = fread($this->findProc['pipe'], 8192);

        if ($chunk !== false && $chunk !== '') {
            $this->findProc['buf'] .= $chunk;
        }

        // Process complete lines
        while (($pos = strpos($this->findProc['buf'], "\n")) !== false) {
            $line = substr($this->findProc['buf'], 0, $pos);
            $this->findProc['buf'] = substr($this->findProc['buf'], $pos + 1);

            $dir = rtrim($line, "\r");

            if ($dir === '') {
                continue;
            }

            $this->registerDir($dir);
        }

        // Check if find has exited
        $status = proc_get_status($this->findProc['proc']);

        if (! $status['running']) {
            // Drain any remaining buffer
            $remaining = stream_get_contents($this->findProc['pipe']);

            if ($remaining !== false && $remaining !== '') {
                $this->findProc['buf'] .= $remaining;

                // Process leftover lines (no trailing newline on last entry)
                foreach (explode("\n", $this->findProc['buf']) as $line) {
                    $dir = rtrim($line, "\r");

                    if ($dir === '') {
                        continue;
                    }

                    $this->registerDir($dir);
                }
            }

            fclose($this->findProc['pipe']);
            proc_close($this->findProc['proc']);
            $this->findProc = null;
            $this->findDone = true;
        }
    }

    /**
     * Validate and register a discovered directory into state.
     * Determines the type (vendor/node) and checks the manifest file exists.
     */
    protected function registerDir(string $dir): void
    {
        if (isset($this->state[$dir])) {
            return;
        }

        $parent = dirname($dir);
        $name = basename($dir);

        // Determine type and required manifest
        if ($name === 'node_modules') {
            $type = 'node';
            $manifest = $parent . DIRECTORY_SEPARATOR . 'package.json';
        } else {
            $type = 'vendor';
            $manifest = $parent . DIRECTORY_SEPARATOR . 'composer.json';
        }

        // Only include dirs that belong to a real project
        if (! file_exists($manifest)) {
            return;
        }

        $this->dirs[] = $dir;
        $this->state[$dir] = [
            'project' => basename($parent),
            'size' => null,
            'status' => 'calculating',
            'type' => $type,
        ];

        $this->enqueueSizeProcess($dir);
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
     * Start a background du -s process for one directory.
     */
    protected function startSizeProcess(string $dir): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(['du', '-s', $dir], $descriptors, $pipes);

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
        if ($this->findProc !== null) {
            fclose($this->findProc['pipe']);
            proc_terminate($this->findProc['proc']);
            proc_close($this->findProc['proc']);
            $this->findProc = null;
        }

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
        $byte = @fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return;
        }

        $count = count($this->dirs);

        if ($byte === "\033") {
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
     */
    protected function updateVisibleRows(): void
    {
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
    }

    protected function printHelp(): void
    {
        $this->line('  <fg=gray>↑↓ navigate   <fg=green>space</> delete   <fg=red>q</> quit</>');
        $this->newLine();
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
        // header = 1 newLine() + 1 help line + 1 newLine() = 3 lines printed before renderList()
        $headerLines = 3;
        $total = $headerLines + $this->renderedLines;

        if ($total > 0) {
            // Move cursor up to the first header line, then erase from cursor to end of screen
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
        $totalSize = 0;
        $allSized = true;
        $deletedCount = 0;

        foreach ($this->state as $info) {
            if ($info['size'] !== null) {
                $totalSize += $info['size'];
            }

            if ($info['status'] === 'calculating') {
                $allSized = false;
            }

            if ($info['status'] === 'deleted') {
                $deletedCount++;
            }
        }

        // Clamp cursor / offset to current list length
        if ($count > 0 && $this->cursor >= $count) {
            $this->cursor = $count - 1;
        }

        if ($this->scrollOffset > max(0, $count - $this->visibleRows)) {
            $this->scrollOffset = max(0, $count - $this->visibleRows);
        }

        $visibleEnd = min($this->scrollOffset + $this->visibleRows, $count);

        // Empty state while searching — hide once search is done (status bar shows "done")
        if ($count === 0 && ! $this->findDone) {
            $spinner = $this->spinnerFrames[$this->spinnerFrame];
            $this->line("\033[K  <fg=gray>{$spinner} Searching...</>");
            $this->renderedLines++;
        }

        for ($i = $this->scrollOffset; $i < $visibleEnd; $i++) {
            $dir = $this->dirs[$i];
            $info = $this->state[$dir];
            $isActive = ($i === $this->cursor);

            $indicator = $isActive ? '<fg=cyan>▶</> ' : '  ';

            switch ($info['status']) {
                case 'calculating':
                    $badgeText = 'calculating...';
                    $badge = "<fg=gray>{$badgeText}</>";

                    break;
                case 'ready':
                    $badgeText = $this->formatSize($info['size']);
                    $badge = "<fg=yellow>{$badgeText}</>";

                    break;
                case 'deleting':
                    $badgeText = 'deleting...';
                    $badge = "<fg=yellow;options=bold>{$badgeText}</>";

                    break;
                case 'deleted':
                    $badgeText = 'deleted ✓';
                    $badge = "<fg=green;options=bold>{$badgeText}</>";

                    break;
                default:
                    $badgeText = '';
                    $badge = '';
            }

            // Number prefix: right-padded to the width of the largest index, e.g. " 1." " 2." "10."
            $numWidth = mb_strlen((string) $count); // digits in total count
            $numberText = str_pad((string) ($i + 1), $numWidth, ' ', STR_PAD_LEFT) . '.';
            $number = $info['status'] === 'deleted'
                ? "<fg=gray>{$numberText}</>"
                : ($isActive ? "<fg=cyan>{$numberText}</>" : "<fg=gray>{$numberText}</>");

            // prefixLen = 1 (leading space) + 2 (indicator) + numWidth + 1 (dot) + 1 (space)
            $prefixLen = 1 + 2 + $numWidth + 1 + 1;
            $badgePad = 1;      // 1 space before right edge

            // In --all mode prepend a type tag to the badge so the user can distinguish dirs
            $typeTag = '';
            $typeTagText = '';
            if ($this->option('all')) {
                if ($info['type'] === 'node') {
                    $typeTagText = '[node] ';
                    $typeTag = '<fg=blue>' . $typeTagText . '</>';
                } else {
                    $typeTagText = '[vendor] ';
                    $typeTag = '<fg=magenta>' . $typeTagText . '</>';
                }
            }

            $rightWidth = mb_strlen($typeTagText) + mb_strlen($badgeText) + $badgePad;
            $projectWidth = max(10, $this->termWidth - $prefixLen - $rightWidth);
            $projectPlain = mb_strlen($info['project']) > $projectWidth
                ? mb_substr($info['project'], 0, $projectWidth - 1) . '…'
                : str_pad($info['project'], $projectWidth);

            $displayProject = $info['status'] === 'deleted'
                ? "<fg=gray>{$projectPlain}</>"
                : ($isActive ? "<options=bold;fg=cyan>{$projectPlain}</>" : "<options=bold>{$projectPlain}</>");

            $this->line(sprintf("\033[K %s%s %s%s%s", $indicator, $number, $displayProject, $typeTag, $badge));
            $this->renderedLines++;

            // Path: aligned to same column as project name (prefixLen spaces)
            $pathColor = $info['status'] === 'deleted' ? 'gray' : ($isActive ? 'cyan' : 'gray');
            $relativePath = ltrim(substr($dir, strlen($this->searchPath)), DIRECTORY_SEPARATOR);
            $displayPath = $relativePath ?: $dir;
            $pathIndent = $prefixLen; // align with project name
            $maxPathWidth = $this->termWidth - $pathIndent;
            if (mb_strlen($displayPath) > $maxPathWidth) {
                $displayPath = '…' . mb_substr($displayPath, mb_strlen($displayPath) - $maxPathWidth + 1);
            }
            $this->line(sprintf("\033[K%s<fg=%s>%s</>", str_repeat(' ', $pathIndent), $pathColor, $displayPath));
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
        $this->line($this->buildStatusBar($count, $totalSize, $allSized, $deletedCount));
        $this->renderedLines++;
    }

    protected function buildStatusBar(int $count, int $totalSize, bool $allSized, int $deletedCount): string
    {
        $spinner = $this->spinnerFrames[$this->spinnerFrame];

        // Search status
        if (! $this->findDone) {
            $searchStatus = "<fg=cyan>{$spinner} searching</>";
        } else {
            $searchStatus = '<fg=gray>done</>';
        }

        // Total size
        if ($count === 0) {
            $totalStr = '';
        } elseif (! $allSized) {
            $totalStr = '  Total: <fg=gray>' . $this->formatSize($totalSize) . ' …</>';
        } else {
            $totalStr = '  Total: <fg=green;options=bold>' . $this->formatSize($totalSize) . '</>';
        }

        // Deleted summary
        $deletedStr = $deletedCount > 0 ? "  <fg=green>{$deletedCount} deleted</>" : '';

        $dirWord = $count === 1 ? 'directory' : 'directories';
        $label = $this->targetLabel();

        return sprintf(
            "\033[K  <fg=green;options=bold>%d</> %s %s  %s%s%s",
            $count,
            $label,
            $dirWord,
            $searchStatus,
            $totalStr,
            $deletedStr
        );
    }

    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

    protected function showSummary(): void
    {
        $deleted = array_filter($this->state, fn ($i) => $i['status'] === 'deleted');
        $freedKb = (int) array_sum(array_column($deleted, 'size'));

        if (count($deleted) > 0) {
            $count = count($deleted);
            $label = $this->targetLabel();
            $this->components->twoColumnDetail(
                "<fg=green;options=bold>Deleted {$count} {$label} " . ($count === 1 ? 'directory' : 'directories') . '</>',
                '<fg=green;options=bold>' . $this->formatSize($freedKb) . ' freed</>'
            );
            $this->newLine();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Human-readable label for the type of directories being targeted.
     */
    protected function targetLabel(): string
    {
        if ($this->option('all')) {
            return 'vendor/node_modules';
        }

        if ($this->option('node')) {
            return 'node_modules';
        }

        return 'vendor';
    }

    protected function formatSize(int|float $size): string
    {
        $units = ['KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    protected function enableRawMode(): void
    {
        $this->sttyOriginal = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo -icanon min 0 time 0 2>/dev/null');

        $this->output->write("\033[?25l"); // hide cursor

        register_shutdown_function(function () {
            $this->disableRawMode();
        });

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
        }
    }

    protected function disableRawMode(): void
    {
        $this->output->write("\033[?25h"); // show cursor

        if ($this->sttyOriginal !== '') {
            shell_exec('stty ' . escapeshellarg($this->sttyOriginal) . ' 2>/dev/null');
            $this->sttyOriginal = '';
        }
    }

    protected function thanks(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Thanks for using CNKill!</>');
    }
}
