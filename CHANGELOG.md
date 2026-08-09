# Changelog

Notable changes land here, newest first. Versions follow
[semantic versioning](https://semver.org/), and the layout borrows from
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

`TableWidget` renders a table: a header row, the rows inside a scroll
window that follows the selected row, and a `(current/total)` indicator
when rows are hidden. Column widths come from `Column::$width` when
pinned, otherwise the leftover width is split between the auto columns
in proportion to the widest content on screen, never below three cells.
Cells are truncated with the upstream width helpers, so no line can
exceed the terminal width. Empty data renders a single "No rows" line.

`Column` describes a column (key, header, width, alignment, sortable
flag, formatter, comparator), with `Align` and `SortDirection` as enums.
Selection state is readable and settable — `getSelectedRow()`,
`getSelectedIndex()`, `setSelectedIndex()` with clamping — and every
change invalidates the render cache.

`TableWidget::defaultStyleSheet()` carries the styles for the `header`,
`selected`, `row-alt`, `scroll-info` and `no-match` pseudo-elements; the
core stylesheet has no rules for third-party widgets, so register it
yourself to get any colour.

Auto columns now stretch to fill the terminal: whatever width is left
over after the pinned columns goes to the last auto column, and every
line is padded to the full width so `selected` and `row-alt` colour the
whole row rather than stopping after the text. Tables built only from
pinned columns keep their width and just get the padding.

The widget is interactive. It implements `FocusableInterface`, so the
focus manager routes keys to it: Up and Down move by a row, PageUp and
PageDown by a window, Home and End jump to the ends, Enter confirms,
Escape and ctrl+c cancel. The cursor stops at the first and last row
instead of wrapping. Pass your own `Keybindings` to the constructor to
rebind any of it.

Moving the cursor fires `RowChangeEvent`, Enter fires `RowSelectEvent`
(both carry the row array and its index), and cancelling fires the core
`CancelEvent`; subscribe with `onRowChange()`, `onRowSelect()` and
`onCancel()`. Without rows, navigation and Enter do nothing while cancel
still works.

Sorting, filtering and the column cursor are still missing.
