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
has to be mapped into objects first. `Column` takes seven parameters and expects
named arguments: positionally, `width`, `align` and `sortable` sit between the
header and the formatter, which reads badly.

A cell value that is not a string is turned into one: scalars and `Stringable`
objects by casting, arrays and other objects into their type name (`array`,
`DateTimeImmutable`), `null` and a missing key into an empty cell. When you want
something else on screen, give the column a formatter.

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

There is a runnable version in
[`examples/demo.php`](https://github.com/Bosun18/tui-datatable/blob/main/examples/demo.php):
15 packages, a status line and a filter on `f`. Wiring the widget into a
framework console command looks like
[`examples/artisan-command.php`](https://github.com/Bosun18/tui-datatable/blob/main/examples/artisan-command.php).
Both links point at GitHub because `examples/` is kept out of the Composer
archive, so you will not find those files under `vendor/`.

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

A `Keybindings` entry replaces the whole action, it does not extend it. That is
why Escape and ctrl+c are spelled out again above: leave them out and the table
loses them. It also means keys added to an action in a later version will not
reach a widget that overrides that action.

## Height

By default the table draws `maxVisible` data rows, ten unless you say
otherwise, and never more lines than the terminal granted: the header keeps
the first line, the scroll indicator is dropped when there is no room for it,
and at one row only the header survives.

When the table shares a screen with other widgets, let the layout decide
instead:

```php
$table->expandVertically(true);
```

The window then comes from the height the layout hands over, so the table
grows and shrinks with the terminal. **While expansion is on, `maxVisible` is
ignored entirely** — it is not a ceiling. `setMaxVisible()` still works and
takes effect the moment you turn expansion back off.

## Sorting and filtering

Sorting is stable and compares the raw cell values with `<=>`, so equal rows
keep the order you gave them in. When the natural comparison is wrong, say
`'10kb'` before `'9kb'` or dates kept as strings, give the column its own
comparator. A column declared `sortable: false` ignores the `s` key, and
`sortBy()` on it throws `InvalidArgumentException` rather than sorting anyway.

The same applies to anything outside ASCII, because `<=>` compares strings byte
by byte. Sorting `ананас, апельсин, banana, яблоко, ёлка` ascending gives
`banana, ананас, апельсин, яблоко, ёлка`: Latin lands before Cyrillic, and `ё`
(U+0451) sits past `я` (U+044F) instead of after `е`. For text in a human
language, sort through a collator:

```php
$collator = new \Collator('ru_RU');

new Column('guest', 'Guest', comparator: static fn (mixed $a, mixed $b): int
    => $collator->compare((string) $a, (string) $b) ?: 0);
```

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

There is no filter input widget in this package, so the text has to come from
somewhere in your app. `onInput()`, which the table gets from the core
`KeybindingsTrait`, runs before `handleInput()` and swallows the bytes when the
callback returns true, which is enough to bind a key of your own:

```php
$input = new InputWidget()->setPrompt('  Filter: ');

$input
    // Filtering as you type reads better than waiting for Enter.
    ->onChange(function (ChangeEvent $event) use ($table): void {
        $event->isBlank() ? $table->clearFilter() : $table->setFilter($event->getValue());
    })
    ->onSubmit(fn () => $tui->setFocus($table));

$table->onInput(function (string $data) use ($tui, $input): bool {
    if ('/' !== $data) {
        return false;   // let the table handle the key
    }

    $tui->setFocus($input);

    return true;        // and keep '/' out of the table
});
```

Empty states are written in English and cannot be changed yet: a table with no
data says `No rows`, and one whose filter matched nothing says `No matches`. The
widget is `final`, so there is no subclass to override them from either. If your
interface is not in English, that shows.

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

Order matters when you set a sort up front: `sortBy()` dispatches immediately,
so a listener registered after it never sees that first event and your status
line starts out empty. Subscribe first, then sort.

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
