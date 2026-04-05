<?php

declare(strict_types=1);

namespace App\Prompts\Renderers;

use App\Prompts\DirectoryListPrompt;
use Laravel\Prompts\Themes\Default\Renderer;

/**
 * Renderer for {@see DirectoryListPrompt}.
 *
 * Each invocation produces a complete frame of the scrollable directory list.
 * The frame is built from the prompt's public state (dirs, state array, cursor
 * position, terminal width, etc.) using the Colors trait methods provided by
 * the Renderer base class.
 *
 * The rendered output intentionally mirrors the visual style of TuiCommand's
 * writeListLines() — three terminal lines per item:
 *   1. Row:  ▶ N. <project name>      [type]  <badge>
 *   2. Meta: <relative path>                   <time ago>
 *   3. Sep:  ─────────────────────────────────────────────
 *
 * Followed by:
 *   - Scroll indicator  (when the list overflows)
 *   - Sort indicator line
 *   - Status bar line
 */
class DirectoryListRenderer extends Renderer
{
    /**
     * Render the directory list prompt.
     */
    public function __invoke(DirectoryListPrompt $prompt): string
    {
        $dirs = $prompt->dirs;
        $entries = $prompt->entries;
        $count = count($dirs);
        $scroll = $prompt->scroll;
        $firstVisible = $prompt->firstVisible;
        $highlighted = $prompt->highlighted;
        $termWidth = $prompt->termWidth;
        $numWidth = mb_strlen((string) $count);

        $visibleEnd = min($firstVisible + $scroll, $count);

        // Empty state — show spinner while searching
        if ($count === 0 && $prompt->isSearching) {
            $this->line("\033[K  " . $this->gray($prompt->spinnerFrame . ' Searching...'));
        }

        // Prefix length: 1 space + 2 (indicator) + numWidth + 1 dot + 1 space
        $prefixLen = 1 + 2 + $numWidth + 1 + 1;

        for ($i = $firstVisible; $i < $visibleEnd; $i++) {
            $dir = $dirs[$i];
            $info = $entries[$dir] ?? ['status' => 'calculating', 'size' => null, 'type' => 'vendor', 'order' => $i];
            $isActive = ($i === $highlighted);

            // Separator above (except the very first visible row)
            if ($i > 0) {
                $sepActive = $isActive || ($i - 1 === $highlighted);
                $this->line($this->renderSeparator($termWidth, $prefixLen, $sepActive));
            }

            $indicator = $isActive ? $this->cyan('▶') . ' ' : '  ';
            $number = $this->renderNumber($i + 1, $numWidth, $info['status'], $isActive);
            [$typeTag, $typeTagText] = $this->renderTypeTag($info['type'], $prompt);
            [$badge, $badgeText] = $this->renderBadge($info['status'], $info['size'] ?? null);
            $displayName = $this->renderName($info, $isActive, $prefixLen, $typeTagText, $badgeText, $termWidth);
            $displayMeta = $this->renderMeta($prompt, $dir, $info, $isActive, $prefixLen, $termWidth);

            $this->line(sprintf("\033[K %s%s %s%s%s", $indicator, $number, $displayName, $typeTag, $badge));
            $this->line($displayMeta);
        }

        // Scroll indicator
        if ($count > $scroll) {
            $this->line(sprintf(
                "\033[K  %s",
                $this->gray(sprintf('%d–%d of %d', $firstVisible + 1, $visibleEnd, $count))
            ));
        }

        // Sort indicator
        $this->line("\033[K  " . $prompt->sortIndicator);

        // Status bar (pre-formatted by TuiCommand and passed in as a string)
        $this->line("\033[K" . $prompt->statusBarLine);

        return $this->output;
    }

    // -------------------------------------------------------------------------
    // Row-part renderers
    // -------------------------------------------------------------------------

    /**
     * Render the row number cell.
     */
    private function renderNumber(int $position, int $numWidth, string $status, bool $isActive): string
    {
        $text = str_pad((string) $position, $numWidth, ' ', STR_PAD_LEFT) . '.';

        if ($status === 'deleted') {
            return $this->gray($text);
        }

        return $isActive ? $this->cyan($text) : $this->gray($text);
    }

    /**
     * Render the type tag badge ([node] / [vendor] / tool-specific).
     * Returns [ansiMarkup, plainText].
     *
     * @return array{string, string}
     */
    private function renderTypeTag(string $type, DirectoryListPrompt $prompt): array
    {
        // For vendor-only or node-only modes there is no type tag.
        // The prompt carries node/composerMode flags via its public properties.
        if (isset($prompt->nodeMode) && $prompt->nodeMode) {
            return ['', ''];
        }

        if (isset($prompt->composerMode) && $prompt->composerMode) {
            return ['', ''];
        }

        return match ($type) {
            'node' => [$this->blue('[node] '),     '[node] '],
            'vendor' => [$this->magenta('[vendor] '), '[vendor] '],
            'npm' => [$this->red(' [npm]'),           ' [npm]'],
            'pnpm-store' => [$this->yellow(' [pnpm store]'),  ' [pnpm store]'],
            'pnpm-cache' => [$this->yellow(' [pnpm cache]'),  ' [pnpm cache]'],
            'yarn' => [$this->cyan(' [yarn]'),          ' [yarn]'],
            'bun' => [$this->magenta(' [bun]'),        ' [bun]'],
            'composer' => [$this->blue(' [composer]'),      ' [composer]'],
            'cpx' => [$this->green(' [cpx]'),          ' [cpx]'],
            default => ['', ''],
        };
    }

    /**
     * Render the status badge (size / calculating / deleting / deleted / failed).
     * Returns [ansiMarkup, plainText].
     *
     * @return array{string, string}
     */
    private function renderBadge(string $status, ?int $size): array
    {
        return match ($status) {
            'calculating' => [$this->gray('calculating...'), 'calculating...'],
            'ready' => [$this->yellow($this->formatSize($size)), $this->formatSize($size)],
            'deleting' => [$this->bold($this->yellow('deleting...')), 'deleting...'],
            'deleted' => [$this->bold($this->green('deleted ✓')), 'deleted ✓'],
            'failed' => [$this->bold($this->red('delete failed')), 'delete failed'],
            default => ['', ''],
        };
    }

    /**
     * Render the project/label name cell, truncated and padded to fill available width.
     *
     * @param  array{project?: string, label?: string, size: int|null, status: string, type: string, order: int}  $info
     */
    private function renderName(array $info, bool $isActive, int $prefixLen, string $typeTagText, string $badgeText, int $termWidth): string
    {
        $name = $info['project'] ?? $info['label'] ?? 'unknown';
        $badgePad = 1;
        $rightWidth = mb_strlen($typeTagText) + mb_strlen($badgeText) + $badgePad;
        $nameWidth = max(10, $termWidth - $prefixLen - $rightWidth);

        $plain = mb_strlen($name) > $nameWidth
            ? mb_substr($name, 0, $nameWidth - 1) . '…'
            : str_pad($name, $nameWidth);

        if ($info['status'] === 'deleted') {
            return $this->gray($plain);
        }

        return $isActive ? $this->bold($this->cyan($plain)) : $this->bold($plain);
    }

    /**
     * Render the meta line (path left-aligned + time-ago right-aligned).
     *
     * @param  array{project?: string, label?: string, size: int|null, status: string, type: string, lastModified?: int|null, order: int}  $info
     */
    private function renderMeta(DirectoryListPrompt $prompt, string $dir, array $info, bool $isActive, int $prefixLen, int $termWidth): string
    {
        $color = ($info['status'] === 'deleted' || ! $isActive) ? 'gray' : 'cyan';

        $lastModifiedText = $this->formatRelativeTime($info['lastModified'] ?? null);
        $gap = 2;
        $rightPad = 1;
        $maxWidth = $termWidth - $prefixLen - $rightPad;
        $rightWidth = mb_strlen($lastModifiedText);
        $pathWidth = max(10, $maxWidth - $rightWidth - $gap);

        // For CnKill dirs we show a relative path; for CacheKill we show the absolute path.
        $basePath = $prompt->searchPath;
        $path = ($basePath !== '')
            ? ltrim(substr($dir, mb_strlen($basePath)), DIRECTORY_SEPARATOR)
            : $dir;

        if ($path === '') {
            $path = $dir;
        }

        if (mb_strlen($path) > $pathWidth) {
            $path = '…' . mb_substr($path, mb_strlen($path) - $pathWidth + 1);
        }

        $line = str_pad($path, $pathWidth) . str_repeat(' ', $gap) . $lastModifiedText . str_repeat(' ', $rightPad);

        $prefix = str_repeat(' ', $prefixLen);

        return sprintf("\033[K%s%s", $prefix, $this->{$color}($line));
    }

    /**
     * Render a horizontal separator between rows.
     */
    private function renderSeparator(int $termWidth, int $prefixLen, bool $isActive): string
    {
        $width = max(10, $termWidth - $prefixLen - 1);
        $dashes = str_repeat('-', $width);
        $prefix = str_repeat(' ', $prefixLen);

        return sprintf("\033[K%s%s", $prefix, $isActive ? $this->cyan($dashes) : $this->gray($dashes));
    }

    // -------------------------------------------------------------------------
    // Formatting helpers (mirrors TuiCommand)
    // -------------------------------------------------------------------------

    private function formatSize(int|float|null $size): string
    {
        if ($size === null || $size === 0) {
            return '0 KB';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    private function formatRelativeTime(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'unknown';
        }

        $seconds = max(0, time() - $timestamp);

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

        return intdiv($days, 365) . 'y ago';
    }
}
