<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable;

/**
 * Describes one column of a TableWidget.
 *
 * A column reads `$row[$key]`, so rows are plain associative arrays. Give
 * `$width` to pin a column to a fixed number of terminal cells, or leave it
 * null and the widget divides the leftover width between the auto columns.
 */
final class Column
{
    /**
     * @param string                                         $key        key to read from each row
     * @param string                                         $header     column title
     * @param ?int                                           $width      fixed width in cells, null for automatic
     * @param bool                                           $sortable   whether sorting by this column is offered
     * @param ?callable(mixed, array<string, mixed>): string $formatter  turns a raw value into display text
     * @param ?callable(mixed, mixed): int                   $comparator compares two raw values, spaceship-style
     */
    public function __construct(
        public readonly string $key,
        public readonly string $header,
        public readonly ?int $width = null,
        public readonly Align $align = Align::Left,
        public readonly bool $sortable = true,
        public readonly mixed $formatter = null,
        public readonly mixed $comparator = null,
    ) {
    }
}
