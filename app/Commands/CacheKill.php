<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\TuiCommand;
use App\Prompts\DirectoryListPrompt;
use App\Prompts\Renderers\DirectoryListRenderer;
use Laravel\Prompts\Key;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

class CacheKill extends Command
{
    use TuiCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache
                            {--sort=default : Sort by default, name, size, or modified}';

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
     *   'failed'      — rm -rf failed
     *
     * @var array<string, array{label: string, type: string, size: int|null, status: string, lastModified: int|null, order: int}>
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
     * The Laravel Prompts component that owns list rendering each frame.
     */
    protected ?DirectoryListPrompt $listPrompt = null;

    public function handle(): int
    {
        if (! $this->initializeSortMode()) {
            return 1;
        }

        $paths = $this->resolveCachePaths();

        if (empty($paths)) {
            $this->newLine();
            $this->line('  <fg=green>No package manager caches found.</>');
            $this->newLine();
            $this->thanks();

            return 0;
        }

        // Populate state and enqueue size calculations before entering the TUI
        $order = 0;

        foreach ($paths as $dir => $entry) {
            $this->dirs[] = $dir;
            $this->state[$dir] = [
                'label' => $entry['label'],
                'type' => $entry['type'],
                'size' => null,
                'status' => 'calculating',
                'lastModified' => filemtime($dir) ?: null,
                'order' => $order++,
            ];
            $this->enqueueSizeProcess($dir);
        }

        $this->listPrompt = new DirectoryListPrompt(
            entries: $this->state,
            dirs: $this->dirs,
            termWidth: $this->termWidth,
            visibleRows: $this->visibleRows,
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

        $this->runTuiLoop(function (): void {
            $this->pollSizeProcesses();
            $this->pollDeleteProcesses();
        });

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
        $home = $this->resolveHomeDir();
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

    protected function cleanupProcesses(): void
    {
        $this->cleanupTuiProcesses();
    }

    protected function writeListLines(): void
    {
        if ($this->listPrompt === null) {
            return;
        }

        $sortedDirs = $this->visibleDirs();

        $this->listPrompt->highlighted = $this->cursor;
        $this->listPrompt->firstVisible = $this->scrollOffset;
        $this->listPrompt->scroll = $this->visibleRows;
        $this->listPrompt->termWidth = $this->termWidth;
        $this->listPrompt->spinnerFrame = $this->spinnerFrames[$this->spinnerFrame];
        $this->listPrompt->isSearching = false;
        $this->listPrompt->sortIndicator = $this->sortIndicator();

        $count = count($sortedDirs);
        [$totalSize, $allSized, $deletedCount, $freedSize] = $this->computeStats();
        $this->listPrompt->statusBarLine = $this->buildStatusBar($count, $totalSize, $allSized, $deletedCount, $freedSize);
        $this->listPrompt->dirs = $sortedDirs;

        $frame = (new DirectoryListRenderer($this->listPrompt))($this->listPrompt);

        $lines = explode("\n", rtrim($frame, "\n"));
        foreach ($lines as $line) {
            $this->line($line);
            $this->renderedLines++;
        }
    }

    /**
     * Forward raw STDIN keys to the DirectoryListPrompt's event system.
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

        $sortedDirs = $this->visibleDirs();
        $this->listPrompt->dirs = $sortedDirs;
        $this->listPrompt->highlighted = $this->cursor;
        $this->listPrompt->firstVisible = $this->scrollOffset;
        $this->listPrompt->scroll = $this->visibleRows;

        $this->listPrompt->emit('key', $key);

        $this->cursor = $this->listPrompt->highlighted;
        $this->scrollOffset = $this->listPrompt->firstVisible;

        if (isset($sortedDirs[$this->cursor])) {
            $this->activeDir = $sortedDirs[$this->cursor];
        }
    }

    protected function headerDescription(): string
    {
        return 'Clear package manager caches to reclaim disk space.';
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
            'failed' => ['  <fg=red;options=bold>delete failed</>', '  delete failed'],
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

}
