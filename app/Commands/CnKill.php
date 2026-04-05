<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TuiCommand;
use App\Prompts\DirectoryListPrompt;
use App\Prompts\Renderers\DirectoryListRenderer;
use Laravel\Prompts\Key;
use LaravelZero\Framework\Commands\Command;

class CnKill extends Command
{
    use TuiCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process {path? : The path to search for vendor directories}
                                     {--maxdepth= : The maximum depth to search for vendor directories}
                                     {--node : Search for node_modules directories only}
                                     {--composer : Search for composer vendor directories only}
                                     {--sort=default : Sort by default, name, size, or modified}';

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
     *   'failed'      — rm -rf failed
     *
     * @var array<string, array{project: string, size: int|null, status: string, type: string, lastModified: int|null, order: int}>
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
     * Cached value of --composer option (set once in handle()).
     */
    protected bool $composerMode = false;

    /**
     * Cached value of --node option (set once in handle()).
     */
    protected bool $nodeMode = false;

    /**
     * Global package-manager cache directories to exclude from scanning.
     * These are handled separately by the `cache` command.
     *
     * @var string[]
     */
    protected array $excludePaths = [];

    /**
     * The Laravel Prompts component that owns list rendering each frame.
     */
    protected ?DirectoryListPrompt $listPrompt = null;

    public function handle(): int
    {
        $searchPath = (string) ($this->argument('path') ?? getcwd());
        $resolvedSearchPath = realpath($searchPath);

        if ($resolvedSearchPath === false || ! is_dir($resolvedSearchPath)) {
            $this->error('The provided path does not exist or is not a directory: ' . $searchPath);

            return 1;
        }

        $this->searchPath = $this->normalizePath($resolvedSearchPath);

        // Cache options once — avoids repeated option() calls in the hot render loop
        $this->composerMode = (bool) $this->option('composer');
        $this->nodeMode = (bool) $this->option('node');

        // Validate --maxdepth early, before entering raw mode
        $maxdepth = $this->option('maxdepth');
        if ($maxdepth !== null && (! ctype_digit((string) $maxdepth) || (int) $maxdepth < 1)) {
            $this->error('--maxdepth must be a positive integer.');

            return 1;
        }

        if (! $this->initializeSortMode()) {
            return 1;
        }

        // Resolve package-manager cache dirs to exclude from scanning
        $this->excludePaths = $this->resolveExcludePaths();

        // Start the find process in the background (non-blocking)
        $this->startFindProcess($this->searchPath);

        // Build the prompt that owns list rendering each frame.
        // $this->state and $this->dirs are passed by reference so background
        // process mutations are immediately visible to the renderer.
        $this->listPrompt = new DirectoryListPrompt(
            entries: $this->state,
            dirs: $this->dirs,
            termWidth: $this->termWidth,
            visibleRows: $this->visibleRows,
            searchPath: $this->searchPath,
            onDelete: function (string $dir): void {
                $this->state[$dir]['status'] = 'deleting';
                $this->startDeleteProcess($dir);
            },
            onSortCycle: function (): void {
                $this->cycleSortMode();
                if ($this->listPrompt !== null) {
                    $this->listPrompt->highlighted = 0;
                    $this->listPrompt->firstVisible = 0;
                }
            },
            onSortToggle: function (): void {
                $this->toggleSortDirection();
                if ($this->listPrompt !== null) {
                    $this->listPrompt->highlighted = 0;
                    $this->listPrompt->firstVisible = 0;
                }
            },
            onQuit: function (): void {
                $this->running = false;
            },
        );

        $this->runTuiLoop(
            function (): void {
                $this->pollFindProcess();
                $this->pollSizeProcesses();
                $this->pollDeleteProcesses();
            },
            fn (): bool => $this->findDone && empty($this->dirs)
        );

        // If the list is empty after find completes, say so
        if (empty($this->dirs)) {
            $this->line('  <fg=green>No ' . $this->targetLabel() . ' directories found in this path.</>');
        } else {
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

        $xdgCache = rtrim((string) ($_SERVER['XDG_CACHE_HOME'] ?? ''), DIRECTORY_SEPARATOR) ?: $home . '/.cache';

        $candidates = [
            // npm
            rtrim((string) shell_exec('npm config get cache 2>/dev/null'), "\n\r") ?: $home . '/.npm',
            // pnpm content-addressable store
            rtrim((string) shell_exec('pnpm store path 2>/dev/null'), "\n\r") ?: $home . '/.local/share/pnpm/store',
            // pnpm metadata + dlx cache (separate from the store)
            $xdgCache . '/pnpm',
            // yarn
            rtrim((string) shell_exec('yarn cache dir 2>/dev/null'), "\n\r") ?: $xdgCache . '/yarn',
            // bun (no query command — path is always fixed)
            $home . '/.bun/install/cache',
            // composer
            rtrim((string) shell_exec('composer config cache-dir 2>/dev/null'), "\n\r") ?: $xdgCache . '/composer',
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

        if ($this->nodeMode) {
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
        } elseif ($this->composerMode) {
            // Prune node_modules (don't print), prune+print vendor
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
        } else {
            // Default: prune+print both vendor and node_modules
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

    protected function normalizePath(string $path): string
    {
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

        return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
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

        $order = count($this->dirs);
        $this->dirs[] = $dir;
        $this->state[$dir] = [
            'project' => basename($parent),
            'size' => null,
            'status' => 'calculating',
            'type' => $type,
            'lastModified' => $this->resolveLastModified($parent, $dir, $type),
            'order' => $order,
        ];

        $this->enqueueSizeProcess($dir);
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

        $this->cleanupTuiProcesses();
    }

    /**
     * Resolve the most useful last-modified timestamp we can show for a project.
     */
    protected function resolveLastModified(string $projectPath, string $dir, string $type): ?int
    {
        $candidates = [$projectPath, $dir];

        if ($type === 'node') {
            $candidates = array_merge($candidates, [
                $projectPath . DIRECTORY_SEPARATOR . 'package.json',
                $projectPath . DIRECTORY_SEPARATOR . 'package-lock.json',
                $projectPath . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml',
                $projectPath . DIRECTORY_SEPARATOR . 'yarn.lock',
                $projectPath . DIRECTORY_SEPARATOR . 'bun.lock',
                $projectPath . DIRECTORY_SEPARATOR . 'bun.lockb',
            ]);
        } else {
            $candidates = array_merge($candidates, [
                $projectPath . DIRECTORY_SEPARATOR . 'composer.json',
                $projectPath . DIRECTORY_SEPARATOR . 'composer.lock',
            ]);
        }

        $lastModified = null;

        foreach ($candidates as $candidate) {
            if (! file_exists($candidate)) {
                continue;
            }

            $mtime = filemtime($candidate);

            if ($mtime === false) {
                continue;
            }

            $lastModified = max($lastModified ?? $mtime, $mtime);
        }

        return $lastModified;
    }

    protected function writeListLines(): void
    {
        if ($this->listPrompt === null) {
            return;
        }

        // Provide the sorted, visible-order dirs to the prompt each frame.
        $sortedDirs = $this->visibleDirs();

        // Mirror TuiCommand cursor state into the prompt.
        $this->listPrompt->highlighted = $this->cursor;
        $this->listPrompt->firstVisible = $this->scrollOffset;
        $this->listPrompt->scroll = $this->visibleRows;
        $this->listPrompt->termWidth = $this->termWidth;
        $this->listPrompt->spinnerFrame = $this->spinnerFrames[$this->spinnerFrame];
        $this->listPrompt->isSearching = ! $this->findDone;
        $this->listPrompt->sortIndicator = $this->sortIndicator();

        $count = count($sortedDirs);
        [$totalSize, $allSized, $deletedCount, $freedSize] = $this->computeStats();
        $this->listPrompt->statusBarLine = $this->buildStatusBar($count, $totalSize, $allSized, $deletedCount, $freedSize);

        // Swap prompt's dirs to the current sorted order for rendering, then restore.
        $this->listPrompt->dirs = $sortedDirs;

        $frame = (new DirectoryListRenderer($this->listPrompt))($this->listPrompt);

        $lines = explode("\n", rtrim($frame, "\n"));
        foreach ($lines as $line) {
            $this->line($line);
            $this->renderedLines++;
        }
    }

    /**
     * Override TuiCommand's raw-input handler to forward keys to the
     * DirectoryListPrompt's event system. The prompt's on('key') callbacks
     * perform navigation and fire onDelete / onSortCycle / onSortToggle / onQuit.
     * After the prompt handles the key we sync its highlighted/firstVisible back
     * to TuiCommand's cursor/scrollOffset so the rest of the loop stays consistent.
     */
    protected function handleInput(): void
    {
        if ($this->listPrompt === null) {
            parent::handleInput();

            return;
        }

        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return;
        }

        // Re-assemble multi-byte escape sequences the same way TuiCommand does,
        // but map them to the Key:: constants the prompt understands.
        if ($byte === "\033") {
            $seq = fread(STDIN, 2);

            $key = match ($seq) {
                '[A' => Key::UP,
                '[B' => Key::DOWN,
                '[C' => Key::RIGHT,
                '[D' => Key::LEFT,
                default => $byte . $seq,
            };
        } else {
            $key = $byte;
        }

        // Sync the current TuiCommand cursor state into the prompt before the key fires.
        $sortedDirs = $this->visibleDirs();
        $this->listPrompt->dirs = $sortedDirs;
        $this->listPrompt->highlighted = $this->cursor;
        $this->listPrompt->firstVisible = $this->scrollOffset;
        $this->listPrompt->scroll = $this->visibleRows;

        // Fire the prompt's key event.
        $this->listPrompt->emit('key', $key);

        // Sync the prompt's updated cursor back to TuiCommand state.
        $this->cursor = $this->listPrompt->highlighted;
        $this->scrollOffset = $this->listPrompt->firstVisible;

        if (isset($sortedDirs[$this->cursor])) {
            $this->activeDir = $sortedDirs[$this->cursor];
        }
    }

    protected function headerDescription(): string
    {
        return 'Remove composer vendor and node_modules directories in your projects to save disk space.';
    }

    /**
     * Render the type tag for default (both) mode (e.g. "[node] " or "[vendor] ").
     * Returns [markup, plainText] — plainText is used for width calculations.
     *
     * @return array{string, string}
     */
    protected function renderTypeTag(string $type): array
    {
        if ($this->nodeMode || $this->composerMode) {
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
            'failed' => ['<fg=red;options=bold>delete failed</>', 'delete failed'],
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
    protected function renderMetaLine(string $dir, ?int $lastModified, string $status, bool $isActive, int $prefixLen): string
    {
        $color = ($status === 'deleted' || ! $isActive) ? 'gray' : 'cyan';

        $relativePath = ltrim(substr($dir, strlen($this->searchPath)), DIRECTORY_SEPARATOR);
        $path = $relativePath ?: $dir;
        $lastModifiedText = $this->formatRelativeTime($lastModified);
        $gap = 2;
        $rightPad = 1;
        $maxWidth = $this->termWidth - $prefixLen - $rightPad;
        $rightWidth = mb_strlen($lastModifiedText);
        $pathWidth = max(10, $maxWidth - $rightWidth - $gap);

        if (mb_strlen($path) > $pathWidth) {
            $path = '…' . mb_substr($path, mb_strlen($path) - $pathWidth + 1);
        }

        $line = str_pad($path, $pathWidth) . str_repeat(' ', $gap) . $lastModifiedText . str_repeat(' ', $rightPad);

        return sprintf("\033[K%s<fg=%s>%s</>", str_repeat(' ', $prefixLen), $color, $line);
    }

    protected function listRowHeight(): int
    {
        return 3;
    }

    protected function renderSeparator(int $prefixLen, bool $isActive): string
    {
        $width = max(10, $this->termWidth - $prefixLen - 1);
        $color = $isActive ? 'cyan' : 'gray';

        return sprintf("\033[K%s<fg=%s>%s</>", str_repeat(' ', $prefixLen), $color, str_repeat('-', $width));
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
        if ($this->nodeMode) {
            return 'node_modules';
        }

        if ($this->composerMode) {
            return 'vendor';
        }

        return 'vendor/node_modules';
    }
}
