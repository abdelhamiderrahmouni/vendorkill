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
     * Cached value of --all option (set once in handle()).
     */
    protected bool $allMode = false;

    /**
     * Cached value of --node option (set once in handle()).
     */
    protected bool $nodeMode = false;

    /**
     * Spinner frame index.
     */
    protected int $spinnerFrame = 0;

    /** @var string[] */
    protected array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Global package-manager cache directories to exclude from scanning.
     * These are handled separately by the `cache` command.
     *
     * @var string[]
     */
    protected array $excludePaths = [];

    public function handle(): int
    {
        $searchPath = $this->argument('path') ?? getcwd();
        $this->searchPath = rtrim((string) realpath($searchPath), DIRECTORY_SEPARATOR);

        // Cache options once — avoids repeated option() calls in the hot render loop
        $this->allMode = (bool) $this->option('all');
        $this->nodeMode = (bool) $this->option('node');

        // Validate --maxdepth early, before entering raw mode
        $maxdepth = $this->option('maxdepth');
        if ($maxdepth !== null && (! ctype_digit((string) $maxdepth) || (int) $maxdepth < 1)) {
            $this->error('--maxdepth must be a positive integer.');

            return 1;
        }

        // Resolve package-manager cache dirs to exclude from scanning
        $this->excludePaths = $this->resolveExcludePaths();

        // Enter raw mode and show the UI immediately
        $this->enableRawMode();

        $this->printHeader();
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
     * Resolve the set of global package-manager cache directories that should
     * be excluded from scanning. Each tool is queried for its actual configured
     * path; if the query fails or the tool is not installed, a known default is
     * used. Paths that do not exist on disk are omitted.
     *
     * @return string[]
     */
    protected function resolveExcludePaths(): array
    {
        $home = rtrim((string) ($_SERVER['HOME'] ?? (function_exists('posix_getuid')
            ? (posix_getpwuid(posix_getuid())['dir'] ?? '')
            : '')), DIRECTORY_SEPARATOR);

        $candidates = [
            // npm
            rtrim((string) shell_exec('npm config get cache 2>/dev/null'), "\n\r") ?: $home . '/.npm',
            // pnpm
            rtrim((string) shell_exec('pnpm store path 2>/dev/null'), "\n\r") ?: $home . '/.local/share/pnpm/store',
            // yarn
            rtrim((string) shell_exec('yarn cache dir 2>/dev/null'), "\n\r") ?: $home . '/.cache/yarn',
            // bun (no query command — path is always fixed)
            $home . '/.bun/install/cache',
            // composer
            rtrim((string) shell_exec('composer config cache-dir 2>/dev/null'), "\n\r") ?: $home . '/.cache/composer',
            // cpx (composer package executor — path is always fixed)
            $home . '/.cpx',
        ];

        return array_values(array_filter(
            $candidates,
            fn (string $p) => $p !== '' && $p !== $home && is_dir($p)
        ));
    }

    /**
     * Launch `find` as a non-blocking background process.
     */
    protected function startFindProcess(string $searchPath): void
    {
        $maxdepth = $this->option('maxdepth');

        $args = ['find', $searchPath];

        if ($maxdepth !== null) {
            $args[] = '-maxdepth';
            $args[] = $maxdepth;
        }

        // Prune known package-manager cache directories so find never descends into them.
        foreach ($this->excludePaths as $excluded) {
            $args = array_merge($args, ['-path', $excluded, '-prune', '-o']);
        }

        if ($this->allMode) {
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
        } elseif ($this->nodeMode) {
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

        $chunk = fread($this->findProc['pipe'], 8192);

        if ($chunk !== false && $chunk !== '') {
            $this->findProc['buf'] .= $chunk;
        }

        $this->drainFindBuffer(consumeAll: false);

        $status = proc_get_status($this->findProc['proc']);

        if (! $status['running']) {
            $remaining = stream_get_contents($this->findProc['pipe']);

            if ($remaining !== false && $remaining !== '') {
                $this->findProc['buf'] .= $remaining;
            }

            $this->drainFindBuffer(consumeAll: true);

            fclose($this->findProc['pipe']);
            proc_close($this->findProc['proc']);
            $this->findProc = null;
            $this->findDone = true;
        }
    }

    /**
     * Register all complete lines (and optionally partial final line) from the find buffer.
     * When $consumeAll is true the remaining buffer is treated as a final line even without a newline.
     */
    protected function drainFindBuffer(bool $consumeAll): void
    {
        while (($pos = strpos($this->findProc['buf'], "\n")) !== false) {
            $line = substr($this->findProc['buf'], 0, $pos);
            $this->findProc['buf'] = substr($this->findProc['buf'], $pos + 1);

            $dir = rtrim($line, "\r");

            if ($dir !== '') {
                $this->registerDir($dir);
            }
        }

        if ($consumeAll && $this->findProc['buf'] !== '') {
            $dir = rtrim($this->findProc['buf'], "\r\n");

            if ($dir !== '') {
                $this->registerDir($dir);
            }

            $this->findProc['buf'] = '';
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

        // Safety-net: skip anything rooted inside a known cache directory.
        // The find prune predicates handle most cases, but this catches edge
        // cases such as the user passing a cache dir directly as $searchPath.
        foreach ($this->excludePaths as $excluded) {
            if ($dir === $excluded || str_starts_with($dir, $excluded . DIRECTORY_SEPARATOR)) {
                return;
            }
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

        $this->line('  <fg=gray>Remove composer vendor and node_modules directories in your projects to save disk space.</>');
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
        [$totalSize, $allSized, $deletedCount, $freedSize] = $this->computeStats();

        // Clamp cursor / offset to current list length
        if ($count > 0 && $this->cursor >= $count) {
            $this->cursor = $count - 1;
        }

        if ($this->scrollOffset > max(0, $count - $this->visibleRows)) {
            $this->scrollOffset = max(0, $count - $this->visibleRows);
        }

        $visibleEnd = min($this->scrollOffset + $this->visibleRows, $count);

        // Empty state while searching — hidden once done (status bar shows "done")
        if ($count === 0 && ! $this->findDone) {
            $spinner = $this->spinnerFrames[$this->spinnerFrame];
            $this->line("\033[K  <fg=gray>{$spinner} Searching...</>");
            $this->renderedLines++;
        }

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
            $displayProject = $this->renderProject($info['project'], $info['status'], $isActive, $prefixLen, $typeTagText, $badgeText);
            $displayPath = $this->renderPath($dir, $info['status'], $isActive, $prefixLen);

            $this->line(sprintf("\033[K %s%s %s%s%s", $indicator, $number, $displayProject, $typeTag, $badge));
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
     * Returns the formatted markup string (e.g. "<fg=cyan> 1.</>").
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
     * Render the type tag for --all mode (e.g. "[node] " or "[vendor] ").
     * Returns [markup, plainText] — plainText is used for width calculations.
     *
     * @return array{string, string}
     */
    protected function renderTypeTag(string $type): array
    {
        if (! $this->allMode) {
            return ['', ''];
        }

        if ($type === 'node') {
            return ['<fg=blue>[node] </>', '[node] '];
        }

        return ['<fg=magenta>[vendor] </>', '[vendor] '];
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
            'calculating' => ['<fg=gray>calculating...</>', 'calculating...'],
            'ready' => ['<fg=yellow>' . $this->formatSize($size) . '</>', $this->formatSize($size)],
            'deleting' => ['<fg=yellow;options=bold>deleting...</>', 'deleting...'],
            'deleted' => ['<fg=green;options=bold>deleted ✓</>', 'deleted ✓'],
            default => ['', ''],
        };
    }

    /**
     * Render the project name cell, truncated and padded to fill available width.
     */
    protected function renderProject(string $project, string $status, bool $isActive, int $prefixLen, string $typeTagText, string $badgeText): string
    {
        $badgePad = 1; // 1 space before right edge
        $rightWidth = mb_strlen($typeTagText) + mb_strlen($badgeText) + $badgePad;
        $projectWidth = max(10, $this->termWidth - $prefixLen - $rightWidth);

        $plain = mb_strlen($project) > $projectWidth
            ? mb_substr($project, 0, $projectWidth - 1) . '…'
            : str_pad($project, $projectWidth);

        if ($status === 'deleted') {
            return "<fg=gray>{$plain}</>";
        }

        return $isActive ? "<options=bold;fg=cyan>{$plain}</>" : "<options=bold>{$plain}</>";
    }

    /**
     * Render the path line beneath the project name, truncated to fit the terminal width.
     */
    protected function renderPath(string $dir, string $status, bool $isActive, int $prefixLen): string
    {
        $color = ($status === 'deleted' || ! $isActive) ? 'gray' : 'cyan';

        $relativePath = ltrim(substr($dir, strlen($this->searchPath)), DIRECTORY_SEPARATOR);
        $path = $relativePath ?: $dir;

        $maxWidth = $this->termWidth - $prefixLen;

        if (mb_strlen($path) > $maxWidth) {
            $path = '…' . mb_substr($path, mb_strlen($path) - $maxWidth + 1);
        }

        return sprintf("\033[K%s<fg=%s>%s</>", str_repeat(' ', $prefixLen), $color, $path);
    }

    protected function buildStatusBar(int $count, int $totalSize, bool $allSized, int $deletedCount, int $freedSize = 0): string
    {
        $spinner = $this->spinnerFrames[$this->spinnerFrame];
        $searchStatus = $this->findDone
            ? '<fg=gray>done</>'
            : "<fg=cyan>{$spinner} searching</>";

        $totalStr = $this->buildTotalStr($count, $totalSize, $allSized);
        $deletedStr = $deletedCount > 0
            ? "  <fg=green>{$deletedCount} deleted</> <fg=green;options=bold>(" . $this->formatSize($freedSize) . ' freed)</>'
            : '';

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

        $label = $this->targetLabel();
        $dirWord = $count === 1 ? 'directory' : 'directories';

        $this->components->twoColumnDetail(
            "<fg=green;options=bold>Deleted {$count} {$label} {$dirWord}</>",
            '<fg=green;options=bold>' . $this->formatSize($this->freedSizeKb()) . ' freed</>'
        );
        $this->newLine();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Human-readable label for the type of directories being targeted.
     */
    protected function targetLabel(): string
    {
        if ($this->allMode) {
            return 'vendor/node_modules';
        }

        if ($this->nodeMode) {
            return 'node_modules';
        }

        return 'vendor';
    }

    /**
     * Total size in KB of all deleted directories.
     */
    protected function freedSizeKb(): int
    {
        return (int) array_sum(array_column(
            array_filter($this->state, fn ($i) => $i['status'] === 'deleted'),
            'size'
        ));
    }

    protected function formatSize(int|float $size): string
    {
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
