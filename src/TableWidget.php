<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable;

use Bosun18\TuiDataTable\Event\FilterChangeEvent;
use Bosun18\TuiDataTable\Event\RowChangeEvent;
use Bosun18\TuiDataTable\Event\RowSelectEvent;
use Bosun18\TuiDataTable\Event\SortChangeEvent;
use Bosun18\TuiDataTable\Internal\Viewport;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A data table for symfony/tui: columns describe the shape, rows are plain
 * associative arrays.
 *
 * Cell text is written to the terminal as-is and is not sanitized, matching the
 * upstream convention (see SelectListWidget): never pass untrusted bytes,
 * sanitize them yourself first.
 *
 * The widget styles itself through stylesheet pseudo-elements, and the core
 * stylesheet knows nothing about third-party widgets, so nothing is coloured
 * until you register the defaults:
 *
 *     $tui->addStyleSheet(TableWidget::defaultStyleSheet());
 */
final class TableWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /**
     * Narrowest a column may become before the whole line gets clipped instead.
     */
    private const int MIN_COLUMN_WIDTH = 3;

    /**
     * Blank cells between two columns.
     */
    private const int COLUMN_GAP = 2;

    /** @var list<array<string, mixed>> */
    private array $rows;

    /**
     * Filtered and sorted view of $rows, rebuilt lazily.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $visibleRows = null;

    private int $selectedIndex = 0;

    private int $columnCursor = 0;

    private bool $verticallyExpanded = false;

    /**
     * How many data rows the last render actually drew, so paging moves by what
     * is on screen rather than by a number nobody set.
     */
    private int $renderedWindow = 0;

    private ?string $sortKey = null;

    private ?SortDirection $sortDirection = null;

    /** @var (\Closure(array<string, mixed>): bool)|string|null */
    private \Closure|string|null $filter = null;

    /**
     * @param list<Column>               $columns
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $columns,
        array $rows = [],
        private int $maxVisible = 10,
        ?Keybindings $keybindings = null,
    ) {
        $this->rows = $rows;

        if (null !== $keybindings) {
            $this->setKeybindings($keybindings);
        }
    }

    /**
     * Styles for every pseudo-element this widget renders.
     *
     * Register it once on the Tui instance; your own rules for the same
     * selectors win, since later stylesheets cascade over earlier ones.
     */
    public static function defaultStyleSheet(): StyleSheet
    {
        return new StyleSheet([
            self::class.'::header' => new Style()->withBold(),
            self::class.'::header-cursor' => new Style()->withBold()->withUnderline(),
            self::class.'::header-sorted' => new Style()->withBold()->withColor('cyan'),
            self::class.'::selected' => new Style()->withReverse(),
            self::class.'::row-alt' => new Style()->withDim(),
            self::class.'::scroll-info' => new Style()->withColor('gray'),
            self::class.'::no-match' => new Style()->withColor('yellow'),
        ]);
    }

    /**
     * Replace the data. Selection returns to the first row, as upstream lists do.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return $this
     */
    public function setRows(array $rows): static
    {
        $this->rows = $rows;
        $this->visibleRows = null;
        $this->selectedIndex = 0;
        $this->invalidate();

        return $this;
    }

    /**
     * The selected row of the visible list, so filtering and sorting are
     * already applied.
     *
     * @return array<string, mixed>|null
     */
    public function getSelectedRow(): ?array
    {
        return $this->visibleRows()[$this->selectedIndex] ?? null;
    }

    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
    }

    /**
     * @return $this
     */
    public function setSelectedIndex(int $index): static
    {
        $index = max(0, min($index, \count($this->visibleRows()) - 1));

        if ($this->selectedIndex !== $index) {
            $this->selectedIndex = $index;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Fill the height the layout offers instead of stopping at maxVisible.
     *
     * Off by default. While it is on, `maxVisible` is ignored entirely: the
     * window is whatever `RenderContext::getRows()` grants, minus the header
     * and the scroll indicator.
     *
     * @return $this
     */
    public function expandVertically(bool $expand): static
    {
        if ($this->verticallyExpanded !== $expand) {
            $this->verticallyExpanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->verticallyExpanded;
    }

    /**
     * Number of data rows to draw when vertical expansion is off.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function setMaxVisible(int $rows): static
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException(\sprintf('maxVisible must be at least 1, got %d.', $rows));
        }

        if ($this->maxVisible !== $rows) {
            $this->maxVisible = $rows;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Sort by a column, which must exist and must be sortable.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function sortBy(string $key, SortDirection $direction): static
    {
        $column = $this->findColumn($key);

        if (null === $column) {
            throw new \InvalidArgumentException(\sprintf('There is no column with key "%s".', $key));
        }

        if (!$column->sortable) {
            throw new \InvalidArgumentException(\sprintf('Column "%s" is not sortable.', $key));
        }

        if ($this->sortKey === $key && $this->sortDirection === $direction) {
            return $this;
        }

        $this->sortKey = $key;
        $this->sortDirection = $direction;
        $this->refreshVisibleRows();
        $this->dispatch(new SortChangeEvent($this, $key, $direction));

        return $this;
    }

    /**
     * Back to the order the rows were given in.
     *
     * @return $this
     */
    public function clearSort(): static
    {
        if (null === $this->sortKey) {
            return $this;
        }

        $this->sortKey = null;
        $this->sortDirection = null;
        $this->refreshVisibleRows();
        $this->dispatch(new SortChangeEvent($this, null, null));

        return $this;
    }

    /**
     * @return array{key: string, direction: SortDirection}|null
     */
    public function getSort(): ?array
    {
        if (null === $this->sortKey || null === $this->sortDirection) {
            return null;
        }

        return ['key' => $this->sortKey, 'direction' => $this->sortDirection];
    }

    /**
     * Keep only some rows.
     *
     * A string is matched case-insensitively against the displayed text of
     * every column, formatters included, so you find what you see. A callable
     * is used as a predicate on the raw row.
     *
     * @param string|callable(array<string, mixed>): bool $filter
     *
     * @return $this
     */
    public function setFilter(string|callable $filter): static
    {
        // Strings are compared by value; a callable is wrapped into a fresh
        // Closure, so passing the same function twice does count as a change.
        $filter = \is_string($filter) ? mb_strtolower($filter) : $filter(...);

        if ($this->filter === $filter) {
            return $this;
        }

        $this->filter = $filter;
        $this->refreshVisibleRows();
        $this->dispatch(new FilterChangeEvent($this, \count($this->visibleRows())));

        return $this;
    }

    /**
     * @return $this
     */
    public function clearFilter(): static
    {
        if (null === $this->filter) {
            return $this;
        }

        $this->filter = null;
        $this->refreshVisibleRows();
        $this->dispatch(new FilterChangeEvent($this, \count($this->visibleRows())));

        return $this;
    }

    /**
     * @param callable(RowSelectEvent): void $callback
     *
     * @return $this
     */
    public function onRowSelect(callable $callback): static
    {
        return $this->on(RowSelectEvent::class, $callback);
    }

    /**
     * @param callable(RowChangeEvent): void $callback
     *
     * @return $this
     */
    public function onRowChange(callable $callback): static
    {
        return $this->on(RowChangeEvent::class, $callback);
    }

    /**
     * @param callable(SortChangeEvent): void $callback
     *
     * @return $this
     */
    public function onSortChange(callable $callback): static
    {
        return $this->on(SortChangeEvent::class, $callback);
    }

    /**
     * @param callable(FilterChangeEvent): void $callback
     *
     * @return $this
     */
    public function onFilterChange(callable $callback): static
    {
        return $this->on(FilterChangeEvent::class, $callback);
    }

    /**
     * @param callable(CancelEvent): void $callback
     *
     * @return $this
     */
    public function onCancel(callable $callback): static
    {
        return $this->on(CancelEvent::class, $callback);
    }

    /**
     * Handles raw terminal bytes while the widget has focus.
     *
     * The cursor stops at the first and last row instead of wrapping around:
     * upstream lists wrap, but in a table that costs you your place.
     */
    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        $keys = $this->getKeybindings();

        // The column cursor and sorting work regardless of the data: with no
        // rows there is nothing to reorder, but the header still responds.
        if ($keys->matches($data, 'column_prev')) {
            $this->moveColumnCursor(-1);

            return;
        }

        if ($keys->matches($data, 'column_next')) {
            $this->moveColumnCursor(1);

            return;
        }

        if ($keys->matches($data, 'sort_toggle')) {
            $this->cycleSort();

            return;
        }

        $rows = $this->visibleRows();

        if ($rows) {
            $last = \count($rows) - 1;

            // A page is what the last render drew, which matters once the
            // height comes from the layout instead of maxVisible.
            $page = max(1, 0 !== $this->renderedWindow ? $this->renderedWindow : $this->maxVisible);

            $target = match (true) {
                $keys->matches($data, 'row_up') => $this->selectedIndex - 1,
                $keys->matches($data, 'row_down') => $this->selectedIndex + 1,
                $keys->matches($data, 'page_up') => $this->selectedIndex - $page,
                $keys->matches($data, 'page_down') => $this->selectedIndex + $page,
                $keys->matches($data, 'row_first') => 0,
                $keys->matches($data, 'row_last') => $last,
                default => null,
            };

            if (null !== $target) {
                $this->moveTo($target);

                return;
            }

            if ($keys->matches($data, 'row_confirm')) {
                $row = $this->getSelectedRow();

                if (null !== $row) {
                    $this->dispatch(new RowSelectEvent($this, $row, $this->selectedIndex));
                }

                return;
            }
        }

        if ($keys->matches($data, 'cancel')) {
            $this->dispatch(new CancelEvent($this));
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $terminalColumns = $context->getColumns();
        $terminalRows = max(0, $context->getRows());

        if (0 === $terminalRows) {
            return [];
        }

        $rows = $this->visibleRows();
        $total = \count($rows);

        // The header owns the first line. What is left goes to the data and,
        // when rows are hidden, to the scroll indicator: the render contract
        // caps the output at the rows the context granted.
        $budget = $terminalRows - 1;
        $window = $this->verticallyExpanded ? max(1, $budget) : $this->maxVisible;
        $length = min(Viewport::length($total, $window), $budget);
        $withIndicator = $length < $total && $budget >= 2;

        if ($withIndicator) {
            --$budget;
            $length = min($length, $budget);
        }

        $this->renderedWindow = $length;
        $start = Viewport::start($this->selectedIndex, $total, max(1, $length));
        $visible = \array_slice($rows, $start, $length);

        $widths = $this->resolveWidths($terminalColumns, $visible);
        $lines = [$this->pad($this->renderHeader($widths), $terminalColumns)];

        if (0 === $total) {
            if ($terminalRows >= 2) {
                $empty = null === $this->filter ? '  No rows' : '  No matches';
                $lines[] = $this->applyElement('no-match', $this->pad($empty, $terminalColumns));
            }

            return $this->clip($lines, $terminalColumns);
        }

        foreach ($visible as $offset => $row) {
            $lines[] = $this->renderRow($row, $start + $offset, $widths, $terminalColumns);
        }

        if ($withIndicator) {
            $indicator = AnsiUtils::truncateToWidth(
                \sprintf('  (%d/%d)', $this->selectedIndex + 1, $total),
                max(0, $terminalColumns - 2),
                '',
            );
            $lines[] = $this->applyElement('scroll-info', $this->pad($indicator, $terminalColumns));
        }

        return $this->clip($lines, $terminalColumns);
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'row_up' => [Key::UP],
            'row_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'row_first' => [Key::HOME],
            'row_last' => [Key::END],
            'row_confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
            'column_prev' => [Key::LEFT],
            'column_next' => [Key::RIGHT],
            'sort_toggle' => ['s'],
        ];
    }

    /**
     * The rows to show: the original array with the filter and the sort applied,
     * computed once and kept until one of the three changes.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleRows(): array
    {
        return $this->visibleRows ??= $this->buildVisibleRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVisibleRows(): array
    {
        $rows = $this->rows;

        if (null !== $this->filter) {
            $filter = $this->filter;
            $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $filter)));
        }

        if (null !== $this->sortKey && null !== $this->sortDirection) {
            $rows = $this->sorted($rows, $this->sortKey, $this->sortDirection);
        }

        return $rows;
    }

    /**
     * Sorting is stable: PHP's sort functions have preserved the order of equal
     * elements since 8.0, so rows that compare equal stay as they were given.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $key, SortDirection $direction): array
    {
        $comparator = $this->findColumn($key)?->comparator;
        $sign = SortDirection::Desc === $direction ? -1 : 1;

        usort($rows, static function (array $left, array $right) use ($key, $comparator, $sign): int {
            $a = $left[$key] ?? null;
            $b = $right[$key] ?? null;

            return $sign * (\is_callable($comparator) ? $comparator($a, $b) : $a <=> $b);
        });

        return $rows;
    }

    /**
     * @param array<string, mixed>                          $row
     * @param (\Closure(array<string, mixed>): bool)|string $filter
     */
    private function matches(array $row, \Closure|string $filter): bool
    {
        if ($filter instanceof \Closure) {
            return $filter($row);
        }

        foreach ($this->columns as $column) {
            if (str_contains(mb_strtolower($this->cellText($column, $row)), $filter)) {
                return true;
            }
        }

        return false;
    }

    private function findColumn(string $key): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Drops the cached view and puts the cursor back on the first row, since
     * after a new filter or sort the old position means nothing.
     */
    private function refreshVisibleRows(): void
    {
        $this->visibleRows = null;
        $this->selectedIndex = 0;
        $this->invalidate();
    }

    /**
     * Sort key cycle for the column under the cursor: ascending, descending,
     * off. Unsortable columns ignore the key.
     */
    private function cycleSort(): void
    {
        $column = $this->columns[$this->columnCursor] ?? null;

        if (null === $column || !$column->sortable) {
            return;
        }

        if ($this->sortKey !== $column->key) {
            $this->sortBy($column->key, SortDirection::Asc);

            return;
        }

        if (SortDirection::Asc === $this->sortDirection) {
            $this->sortBy($column->key, SortDirection::Desc);

            return;
        }

        $this->clearSort();
    }

    private function moveColumnCursor(int $delta): void
    {
        $target = max(0, min($this->columnCursor + $delta, \count($this->columns) - 1));

        if ($target === $this->columnCursor) {
            return;
        }

        $this->columnCursor = $target;
        $this->invalidate();
    }

    /**
     * Moves the cursor and announces it, unless it was already at that row.
     */
    private function moveTo(int $index): void
    {
        $before = $this->selectedIndex;
        $this->setSelectedIndex($index);

        if ($before === $this->selectedIndex) {
            return;
        }

        $row = $this->getSelectedRow();

        if (null !== $row) {
            $this->dispatch(new RowChangeEvent($this, $row, $this->selectedIndex));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>            $widths
     */
    private function renderRow(array $row, int $index, array $widths, int $terminalColumns): string
    {
        $line = $this->pad($this->renderCells(
            array_map(fn (Column $column): string => $this->cellText($column, $row), $this->columns),
            $widths,
        ), $terminalColumns);

        if ($index === $this->selectedIndex) {
            return $this->applyElement('selected', $line);
        }

        return 0 === $index % 2 ? $line : $this->applyElement('row-alt', $line);
    }

    /**
     * Header cells are styled one by one: the column under the cursor, the
     * sorted column and everything else get their own element, so the padding
     * of each cell is coloured with it.
     *
     * @param list<int> $widths
     */
    private function renderHeader(array $widths): string
    {
        $cells = [];

        foreach ($this->columns as $index => $column) {
            $cell = $this->fitCell($this->headerText($column), $widths[$index], $column->align);
            $cells[] = $this->applyElement($this->headerElement($index, $column), $cell);
        }

        return implode(str_repeat(' ', self::COLUMN_GAP), $cells);
    }

    /**
     * Header with the sort arrow, which is part of the text and therefore
     * counts towards the column width.
     */
    private function headerText(Column $column): string
    {
        if ($this->sortKey !== $column->key || null === $this->sortDirection) {
            return $column->header;
        }

        return $column->header.(SortDirection::Asc === $this->sortDirection ? ' ↑' : ' ↓');
    }

    /**
     * The cursor wins over the sort marker: it is the one that moves.
     */
    private function headerElement(int $index, Column $column): string
    {
        return match (true) {
            $index === $this->columnCursor => 'header-cursor',
            $this->sortKey === $column->key => 'header-sorted',
            default => 'header',
        };
    }

    /**
     * @param list<string> $texts
     * @param list<int>    $widths
     */
    private function renderCells(array $texts, array $widths): string
    {
        $cells = [];

        foreach ($texts as $index => $text) {
            $cells[] = $this->fitCell($text, $widths[$index], $this->columns[$index]->align);
        }

        return implode(str_repeat(' ', self::COLUMN_GAP), $cells);
    }

    private function fitCell(string $text, int $width, Align $align): string
    {
        $text = AnsiUtils::truncateToWidth($text, $width, '');
        $padding = max(0, $width - AnsiUtils::visibleWidth($text));

        return match ($align) {
            Align::Left => $text.str_repeat(' ', $padding),
            Align::Right => str_repeat(' ', $padding).$text,
            Align::Center => str_repeat(' ', intdiv($padding, 2)).$text.str_repeat(' ', $padding - intdiv($padding, 2)),
        };
    }

    /**
     * Fixed columns keep their width; the rest of the line is split between the
     * auto columns in proportion to the widest content currently on screen.
     *
     * @param list<array<string, mixed>> $visible
     *
     * @return list<int>
     */
    private function resolveWidths(int $terminalColumns, array $visible): array
    {
        $count = \count($this->columns);
        $gaps = self::COLUMN_GAP * max(0, $count - 1);
        $budget = max($count * self::MIN_COLUMN_WIDTH, $terminalColumns - $gaps);

        $widths = [];
        $auto = [];
        $spent = 0;

        foreach ($this->columns as $index => $column) {
            if (null === $column->width) {
                $auto[] = $index;
                continue;
            }

            $widths[$index] = max(self::MIN_COLUMN_WIDTH, $column->width);
            $spent += $widths[$index];
        }

        $remaining = $budget - $spent;
        $desired = [];

        foreach ($auto as $index) {
            $desired[$index] = max(self::MIN_COLUMN_WIDTH, $this->contentWidth($this->columns[$index], $visible));
        }

        $wanted = array_sum($desired);
        $lastAuto = array_key_last($auto);
        $assigned = 0;

        foreach ($auto as $position => $index) {
            $isLast = $position === $lastAuto;

            if ($wanted <= $remaining) {
                // Everything fits, so the last auto column also absorbs the
                // spare room and the table spans the whole line.
                $widths[$index] = $isLast ? $desired[$index] + ($remaining - $wanted) : $desired[$index];
                continue;
            }

            // Too little room: divide it in proportion to the content, and let
            // the last auto column take the remainder so rounding never leaves
            // a stray cell unassigned.
            $widths[$index] = $isLast
                ? max(self::MIN_COLUMN_WIDTH, $remaining - $assigned)
                : max(self::MIN_COLUMN_WIDTH, (int) floor($desired[$index] * $remaining / $wanted));

            $assigned += $widths[$index];
        }

        ksort($widths);

        return array_values($widths);
    }

    /**
     * @param list<array<string, mixed>> $visible
     */
    private function contentWidth(Column $column, array $visible): int
    {
        $width = AnsiUtils::visibleWidth($this->headerText($column));

        foreach ($visible as $row) {
            $width = max($width, AnsiUtils::visibleWidth($this->cellText($column, $row)));
        }

        return $width;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function cellText(Column $column, array $row): string
    {
        $value = $row[$column->key] ?? null;
        $formatter = $column->formatter;

        if (!\is_callable($formatter)) {
            return $this->stringify($value);
        }

        return $formatter($value, $row);
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        // Arrays and plain objects have no sensible cell text; showing the type
        // beats a fatal error inside render().
        return get_debug_type($value);
    }

    /**
     * Pads a line out to the full terminal width.
     *
     * Applied before the element style, so `selected` and `row-alt` colour the
     * whole line instead of stopping after the text.
     */
    private function pad(string $line, int $terminalColumns): string
    {
        $padding = $terminalColumns - AnsiUtils::visibleWidth($line);

        return $padding > 0 ? $line.str_repeat(' ', $padding) : $line;
    }

    /**
     * Last line of defence for the render contract: nothing wider than the
     * terminal may leave this method.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function clip(array $lines, int $terminalColumns): array
    {
        foreach ($lines as $index => $line) {
            if (AnsiUtils::visibleWidth($line) > $terminalColumns) {
                $lines[$index] = AnsiUtils::truncateToWidth($line, $terminalColumns, '');
            }
        }

        return $lines;
    }
}
