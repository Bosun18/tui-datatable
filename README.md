# tui-datatable

A data table widget for [symfony/tui](https://github.com/symfony/tui): keyboard
navigation, scrolling, sorting and filtering over plain PHP arrays.

![demo](.github/demo.gif)

[![CI](https://github.com/Bosun18/tui-datatable/actions/workflows/ci.yml/badge.svg)](https://github.com/Bosun18/tui-datatable/actions/workflows/ci.yml)

## Requirements

PHP 8.4.1 or newer and `symfony/tui` `^8.1`. The TUI component is marked
experimental upstream, which means a minor Symfony release may change its API
without a deprecation cycle. This package pins the minor version and follows the
`Tui` label in symfony/symfony, but if the component moves under you, that is
why.

## Installation

```bash
composer require bosun18/tui-datatable
```

## Quick start

Rows are associative arrays and a `Column` says which key it reads, so nothing
has to be mapped into objects first.

```php
use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\RowSelectEvent;
use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Tui;

$table = new TableWidget(
    columns: [
        new Column('name', 'Package'),
        new Column(
            'downloads',
            'Downloads',
            align: Align::Right,
            formatter: static fn (mixed $value): string => number_format((int) $value),
        ),
        new Column('license', 'License', width: 14, sortable: false),
    ],
    rows: [
        ['name' => 'symfony/tui', 'downloads' => 41207, 'license' => 'MIT'],
        ['name' => 'symfony/console', 'downloads' => 812004331, 'license' => 'MIT'],
    ],
    maxVisible: 15,
);

$tui = new Tui();
$tui->addStyleSheet(TableWidget::defaultStyleSheet());

$table
    ->onRowSelect(static function (RowSelectEvent $event) use ($tui): void {
        // $event->row is the row array, $event->index its position on screen
        $tui->stop();
    })
    ->onCancel(static fn () => $tui->stop());

$tui->add($table);
$tui->setFocus($table);
$tui->run();
```

There is a runnable version in [`examples/demo.php`](examples/demo.php): 15
packages, a status line and a filter on `f`.

## Keybindings

| Keys | What happens |
|---|---|
| Up, Down | move one row |
| PageUp, PageDown | move one window |
| Home, End | jump to the first or last row |
| Left, Right | move the column cursor along the header |
| `s` | sort the column under the cursor: ascending, descending, off |
| Enter | confirm the current row |
| Escape, ctrl+c | cancel |

The cursor stops at the first and last row instead of wrapping around, which is
where this differs from `SelectListWidget` upstream. In a list, wrapping is
convenient; in a table of a few hundred rows, it loses your place.

Pass a `Keybindings` instance to the constructor to change any of it. The demo
adds `q` to the cancel action that way:

```php
new TableWidget($columns, $rows, 10, new Keybindings([
    'cancel' => [Key::ESCAPE, 'ctrl+c', 'q'],
]));
```

## Sorting and filtering

Sorting is stable and compares the raw cell values with `<=>`, so equal rows
keep the order you gave them in. When the natural comparison is wrong, say
`'10kb'` before `'9kb'` or dates kept as strings, give the column its own
comparator. A column declared `sortable: false` ignores the `s` key, and
`sortBy()` on it throws `InvalidArgumentException` rather than sorting anyway.

```php
$table->sortBy('downloads', SortDirection::Desc);
$table->getSort();  // ['key' => 'downloads', 'direction' => SortDirection::Desc]
$table->clearSort();
```

A string filter is matched case-insensitively against the text each column
displays, formatters included, so searching `1,234` finds a row holding
`1234567`. A callable filter is a predicate over the raw row instead.

```php
$table->setFilter('symfony/');
$table->setFilter(static fn (array $row): bool => $row['downloads'] > 1_000_000);
$table->clearFilter();
```

Filtering runs before sorting, both on top of the original array, which is never
mutated. Indexes in events and in `getSelectedIndex()` count the rows currently
on screen, so a filtered table numbers its matches from zero. A new filter or
sort moves the cursor back to the first row: keeping the old position would
point at an unrelated row.

## Styling

The core stylesheet has no rules for third-party widgets, so a table renders
with no colour at all until you register the defaults:

```php
$tui->addStyleSheet(TableWidget::defaultStyleSheet());
```

Seven pseudo-elements are styled: `header`, `header-cursor`, `header-sorted`,
`selected`, `row-alt`, `scroll-info` and `no-match`. Add your own stylesheet
after the defaults to override any of them, since later stylesheets win:

```php
$tui->addStyleSheet(new StyleSheet([
    TableWidget::class.'::selected' => new Style()->withBackground('blue'),
    TableWidget::class.'::header' => new Style()->withBold()->withUnderline(),
]));
```

Row lines are padded to the full terminal width before the style is applied, so
`selected` and `row-alt` colour the whole row and not just the text.

## Events

| Event | When |
|---|---|
| `RowChangeEvent` | the cursor moved to another row |
| `RowSelectEvent` | Enter on a row |
| `SortChangeEvent` | sort changed; key and direction are null once cleared |
| `FilterChangeEvent` | filter changed; carries the number of matches |

The first two carry the row array and its index. Cancelling dispatches the
core `Symfony\Component\Tui\Event\CancelEvent`. Subscribe through
`onRowChange()`, `onRowSelect()`, `onSortChange()`, `onFilterChange()` and
`onCancel()`, or with `on(EventClass::class, $callback)` directly. Events fire
only on a real change, so holding Down at the last row stays quiet.

Cell text goes to the terminal as it is and is not sanitized, same as
`SelectListWidget`. Untrusted content should go through
`Symfony\Component\Tui\Widget\Util\StringUtils::stripControlBytes()` first.

## Roadmap

Mouse support, meaning a click on the header to sort and wheel scrolling, waits
for mouse events to land in the core component; they are not merged yet. The
other open one is taking the height from the layout instead of the `maxVisible`
argument, which needs `VerticallyExpandableInterface`.

## License

MIT
