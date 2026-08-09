# Changelog

Notable changes land here, newest first. Versions follow
[semantic versioning](https://semver.org/), and the layout borrows from
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

`expandVertically(bool)` makes the table fill the height the layout hands
it, which is the other half of the height fix below: capping the output
stops the widget from overflowing on its own, but a table sharing a
screen with a status line still needed a way to learn how much room is
actually left. While expansion is on, `maxVisible` is ignored completely
rather than acting as a ceiling, because a table that quietly shows ten
rows after you asked it to fill the screen is a worse surprise than one
that ignores a number you set earlier. It is off by default, so 0.1.x
behaviour is unchanged, and `isVerticallyExpanded()` reports the state.

`setMaxVisible(int)` changes the fixed window after construction and
throws `InvalidArgumentException` below one row. Paging now moves by the
window that was last drawn instead of by `maxVisible`, which is what you
want once the height comes from the layout.

### Fixed

`render()` never returns more lines than the context granted. The widget
did not read `RenderContext::getRows()` at all, so it always produced
`maxVisible` rows plus a header and an indicator: at `stty rows 10` a
table built for 15 rows returned 17 lines and pushed its own header off
the top of the screen. The core does not trim overflow, it only pads, so
this was on the widget. The header keeps the first line, data fills what
is left, and the scroll indicator is dropped when there is no room for
it; at one row only the header survives, at zero the output is empty.

Note that a table sharing a screen with other widgets can still overflow,
because a non-expanding child is measured with the full height. Vertical
expansion is the fix for that and is not in this release.

## [0.1.1] - 2026-08-09

Documentation only, no code changed.

Using the package in a Laravel command turned up things the README either
got wrong or never said. The links to `examples/` were relative, and
`examples/` is excluded from the Composer archive, so they pointed at
files that do not exist under `vendor/`; they are absolute GitHub links
now. `onInput()` was not mentioned at all, even though it is the only way
to bind a key of your own or hand typing over to an `InputWidget`, so it
now has a paragraph and a worked example. The `Keybindings` snippet
showed that an entry replaces an action but never said so, which is easy
to miss until Escape stops working.

Three more gaps, all found the same way. Sorting warned about `'10kb'`
and dates but not about anything outside ASCII: `<=>` compares bytes, so
Latin sorts before Cyrillic and `ё` (U+0451) lands past `я` — there is a
`Collator` example for that now. The empty-state texts are English and
cannot be changed, which the README now admits instead of leaving you to
find out. And what a non-string cell turns into was only in the PHPDoc:
`Stringable` casts, arrays and other objects show their type name, `null`
and a missing key render empty.

`examples/artisan-command.php` sketches the framework side: where to
build the widget, that `Tui::run()` blocks until `stop()`, and how
control comes back to the command afterwards.

## [0.1.0] - 2026-08-09

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

Sorting and filtering are in. `sortBy()` takes a column key and a
direction, `clearSort()` goes back to the order the rows were given in,
and `getSort()` reports the current state. Left and Right move a cursor
along the header, `s` cycles the column under it through ascending,
descending and off, and the sorted column is marked with `↑` or `↓` —
the arrow is part of the header text, so it counts towards the column
width. Sorting is stable, uses `<=>` on the raw cell values unless the
column carries its own comparator, and refuses columns declared
`sortable: false`: the key is ignored and `sortBy()` throws
`InvalidArgumentException`.

`setFilter()` accepts either a string, matched case-insensitively
against the displayed text of every column, or a predicate over the raw
row; `clearFilter()` drops it. Filtering happens before sorting, both on
top of the untouched original rows, and indexes in events and
`getSelectedIndex()` refer to what is on screen. A new filter or sort
puts the cursor back on the first row. An empty result reads
`No matches` when a filter is active and `No rows` when there is no data
at all.

`SortChangeEvent` (key and direction, both null once cleared) and
`FilterChangeEvent` (match count) fire only when the state really
changed; subscribe with `onSortChange()` and `onFilterChange()`. The
default stylesheet gained `header-cursor` and `header-sorted`.

`examples/demo.php` is a runnable table of 15 packages with a status
line, `q` wired into the cancel action and `f` toggling a canned
filter, and `demo.tape` records it with vhs into `.github/demo.gif`
for the top of the README. The
README covers installation, a quick start, the keybindings, sorting and
filtering, styling and the events; `examples/` and `demo.tape` are
excluded from the Composer archive.

[Unreleased]: https://github.com/Bosun18/tui-datatable/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/Bosun18/tui-datatable/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Bosun18/tui-datatable/releases/tag/v0.1.0
