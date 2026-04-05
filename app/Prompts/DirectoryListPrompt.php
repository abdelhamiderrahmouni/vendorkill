<?php

declare(strict_types=1);

namespace App\Prompts;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

/**
 * A Laravel Prompts component that renders a scrollable, keyboard-navigable
 * list of directories with live status badges (calculating / ready / deleting /
 * deleted / failed).
 *
 * The prompt does NOT manage background processes itself — the owning command
 * (via TuiCommand) drives the loop and mutates $entries / $dirs. The prompt
 * exposes only the presentation state needed by DirectoryListRenderer.
 *
 * Usage:
 *   $prompt = new DirectoryListPrompt(
 *       entries:       &$this->state,
 *       dirs:          &$this->dirs,
 *       termWidth:     $this->termWidth,
 *       visibleRows:   $this->visibleRows,
 *       sortIndicator: $this->sortIndicator(),
 *       isSearching:   !$this->findDone,
 *       spinnerFrame:  $this->spinnerFrames[$this->spinnerFrame],
 *       statusBarLine: $this->buildStatusBar(...),
 *       onDelete:      fn(string $dir) => $this->startDeleteProcess($dir),
 *       onSortCycle:   fn() => $this->cycleSortMode(),
 *       onSortToggle:  fn() => $this->toggleSortDirection(),
 *   );
 *
 * @phpstan-type DirInfo array{project?: string, label?: string, size: int|null, status: string, type: string, lastModified?: int|null, order: int}
 */
class DirectoryListPrompt extends Prompt
{
    /**
     * Index of the currently highlighted row.
     */
    public int $highlighted = 0;

    /**
     * First visible row index.
     */
    public int $firstVisible = 0;

    /**
     * Number of rows visible at once (fits terminal height).
     */
    public int $scroll;

    /**
     * The action to fire when the user presses Space on a row.
     *
     * @var callable(string): void
     */
    public $onDelete;

    /**
     * The action to fire when the user changes the sort mode (presses 's').
     *
     * @var callable(): void
     */
    public $onSortCycle;

    /**
     * The action to fire when the user toggles sort direction (presses 'S').
     *
     * @var callable(): void
     */
    public $onSortToggle;

    /**
     * The action to fire when the user presses 'q' / Ctrl-C / Ctrl-D.
     *
     * @var callable(): void
     */
    public $onQuit;

    /**
     * @param  array<string, array{project?: string, label?: string, size: int|null, status: string, type: string, lastModified?: int|null, order: int}>  $entries  Directory state map (by reference — lives in TuiCommand)
     * @param  string[]  $dirs  Ordered directory paths (by reference — lives in TuiCommand)
     */
    public function __construct(
        /** @var array<string, array{project?: string, label?: string, size: int|null, status: string, type: string, lastModified?: int|null, order: int}> */
        public array &$entries,
        /** @var string[] */
        public array &$dirs,
        public int $termWidth = 80,
        int $visibleRows = 20,
        public string $sortIndicator = '',
        public bool $isSearching = false,
        public string $spinnerFrame = '⠋',
        public string $statusBarLine = '',
        public string $searchPath = '',
        /** When true, the type tag column is hidden (only one type is active). */
        public bool $singleTypeMode = false,
        ?callable $onDelete = null,
        ?callable $onSortCycle = null,
        ?callable $onSortToggle = null,
        ?callable $onQuit = null,
    ) {
        $this->scroll = $visibleRows;

        $this->onDelete = $onDelete ?? static function (string $dir): void {};
        $this->onSortCycle = $onSortCycle ?? static function (): void {};
        $this->onSortToggle = $onSortToggle ?? static function (): void {};
        $this->onQuit = $onQuit ?? static function (): void {};

        $this->on('key', function (string $key): void {
            $count = count($this->dirs);

            match (true) {
                $key === Key::UP, $key === Key::UP_ARROW => $this->moveUp($count),
                $key === Key::DOWN, $key === Key::DOWN_ARROW => $this->moveDown($count),
                $key === Key::RIGHT, $key === Key::RIGHT_ARROW => $this->pageDown($count),
                $key === Key::LEFT, $key === Key::LEFT_ARROW => $this->pageUp(),
                $key === Key::SPACE => $this->deleteHighlighted(),
                $key === 's' => ($this->onSortCycle)(),
                $key === 'S' => ($this->onSortToggle)(),
                $key === 'q',
                $key === Key::CTRL_C,
                $key === Key::CTRL_D => $this->requestQuit(),
                default => null,
            };
        });
    }

    /**
     * The "value" of this prompt is the currently highlighted directory path (or null).
     * The owning command uses the side-effect callbacks rather than the return value.
     */
    public function value(): ?string
    {
        return $this->dirs[$this->highlighted] ?? null;
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    private function moveUp(int $count): void
    {
        if ($this->highlighted > 0) {
            $this->highlighted--;

            if ($this->highlighted < $this->firstVisible) {
                $this->firstVisible = $this->highlighted;
            }
        }
    }

    private function moveDown(int $count): void
    {
        if ($this->highlighted < $count - 1) {
            $this->highlighted++;

            if ($this->highlighted >= $this->firstVisible + $this->scroll) {
                $this->firstVisible = $this->highlighted - $this->scroll + 1;
            }
        }
    }

    private function pageDown(int $count): void
    {
        if ($count === 0) {
            return;
        }

        $newFirst = min($this->firstVisible + $this->scroll, max(0, $count - $this->scroll));
        $delta = $newFirst - $this->firstVisible;
        $this->firstVisible = $newFirst;
        $this->highlighted = min($this->highlighted + $delta, $count - 1);
    }

    private function pageUp(): void
    {
        $newFirst = max($this->firstVisible - $this->scroll, 0);
        $delta = $this->firstVisible - $newFirst;
        $this->firstVisible = $newFirst;
        $this->highlighted = max($this->highlighted - $delta, 0);
    }

    private function deleteHighlighted(): void
    {
        $dir = $this->dirs[$this->highlighted] ?? null;

        if ($dir === null) {
            return;
        }

        $status = $this->entries[$dir]['status'] ?? null;

        if ($status === 'ready' || $status === 'failed') {
            ($this->onDelete)($dir);
        }
    }

    private function requestQuit(): void
    {
        ($this->onQuit)();
        $this->state = 'cancel';
    }

    // -------------------------------------------------------------------------
    // Scroll sync — call each frame before render when external state changes
    // -------------------------------------------------------------------------

    /**
     * Synchronise highlighted/firstVisible after external mutations
     * (e.g. sort changes, new dirs added by the background find process).
     */
    public function syncScroll(): void
    {
        $count = count($this->dirs);

        if ($count === 0) {
            $this->highlighted = 0;
            $this->firstVisible = 0;

            return;
        }

        if ($this->highlighted >= $count) {
            $this->highlighted = $count - 1;
        }

        $maxFirst = max(0, $count - $this->scroll);

        if ($this->firstVisible > $maxFirst) {
            $this->firstVisible = $maxFirst;
        }

        if ($this->highlighted < $this->firstVisible) {
            $this->firstVisible = $this->highlighted;
        }

        if ($this->highlighted >= $this->firstVisible + $this->scroll) {
            $this->firstVisible = max(0, $this->highlighted - $this->scroll + 1);
        }
    }
}
