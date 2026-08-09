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

Keyboard input, events, sorting and filtering are not implemented yet.
