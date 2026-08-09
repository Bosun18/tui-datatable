<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable;

use Bosun18\TuiDataTable\Internal\Viewport;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;

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
final class TableWidget extends AbstractWidget
{
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

    private int $selectedIndex = 0;

    /**
     * @param list<Column>               $columns
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $columns,
        array $rows = [],
        private readonly int $maxVisible = 10,
    ) {
        $this->rows = $rows;
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
        $this->selectedIndex = 0;
        $this->invalidate();

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSelectedRow(): ?array
    {
        return $this->rows[$this->selectedIndex] ?? null;
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
        $index = max(0, min($index, \count($this->rows) - 1));

        if ($this->selectedIndex !== $index) {
            $this->selectedIndex = $index;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $terminalColumns = $context->getColumns();
        $total = \count($this->rows);
        $start = Viewport::start($this->selectedIndex, $total, $this->maxVisible);
        $length = Viewport::length($total, $this->maxVisible);
        $visible = \array_slice($this->rows, $start, $length);

        $widths = $this->resolveWidths($terminalColumns, $visible);
        $lines = [$this->applyElement('header', $this->renderCells(
            array_map(static fn (Column $column): string => $column->header, $this->columns),
            $widths,
        ))];

        if (!$visible) {
            $lines[] = $this->applyElement('no-match', '  No rows');

            return $this->clip($lines, $terminalColumns);
        }

        foreach ($visible as $offset => $row) {
            $lines[] = $this->renderRow($row, $start + $offset, $widths);
        }

        if ($length < $total) {
            $indicator = \sprintf('  (%d/%d)', $this->selectedIndex + 1, $total);
            $lines[] = $this->applyElement(
                'scroll-info',
                AnsiUtils::truncateToWidth($indicator, max(0, $terminalColumns - 2), ''),
            );
        }

        return $this->clip($lines, $terminalColumns);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>            $widths
     */
    private function renderRow(array $row, int $index, array $widths): string
    {
        $line = $this->renderCells(
            array_map(fn (Column $column): string => $this->cellText($column, $row), $this->columns),
            $widths,
        );

        if ($index === $this->selectedIndex) {
            return $this->applyElement('selected', $line);
        }

        return 0 === $index % 2 ? $line : $this->applyElement('row-alt', $line);
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
            if ($wanted <= $remaining) {
                $widths[$index] = $desired[$index];
                continue;
            }

            // The last auto column takes whatever is left, so rounding never
            // leaves a stray cell unassigned.
            $widths[$index] = $position === $lastAuto
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
        $width = AnsiUtils::visibleWidth($column->header);

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
