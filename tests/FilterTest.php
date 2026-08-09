<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\FilterChangeEvent;
use Bosun18\TuiDataTable\Event\RowChangeEvent;
use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

#[CoversClass(TableWidget::class)]
#[CoversClass(FilterChangeEvent::class)]
final class FilterTest extends TestCase
{
    private const string DOWN = "\e[B";

    public function testStringFilterMatchesAnyColumnIgnoringCase(): void
    {
        $widget = $this->table();

        $widget->setFilter('MIT');
        self::assertSame(['alpha', 'gamma'], $this->names($widget));

        $widget->setFilter('bet');
        self::assertSame(['beta'], $this->names($widget));
    }

    public function testStringFilterSearchesFormattedText(): void
    {
        $widget = new TableWidget(
            [
                new Column('name', 'Package'),
                new Column(
                    'qty',
                    'Qty',
                    align: Align::Right,
                    formatter: static fn (mixed $value): string => \is_int($value) ? number_format($value) : '',
                ),
            ],
            [
                ['name' => 'alpha', 'qty' => 1234567],
                ['name' => 'beta', 'qty' => 42],
            ],
        );

        // '1,234' only exists after formatting.
        $widget->setFilter('1,234');

        self::assertSame(['alpha'], $this->names($widget));
    }

    public function testCallableFilterActsAsAPredicate(): void
    {
        $widget = $this->table();

        $widget->setFilter(static fn (array $row): bool => ($row['qty'] ?? 0) > 4);

        self::assertSame(['alpha', 'beta'], $this->names($widget));
    }

    public function testClearFilterBringsEveryRowBack(): void
    {
        $widget = $this->table();
        $widget->setFilter('MIT');

        $widget->clearFilter();

        self::assertSame(['alpha', 'beta', 'gamma'], $this->names($widget));
    }

    public function testFilterAndSortWorkTogether(): void
    {
        $widget = $this->table();

        $widget->setFilter('MIT');
        $widget->sortBy('qty', SortDirection::Desc);

        // 'beta' is filtered out; the remaining two are ordered 9 then 1.
        self::assertSame(['alpha', 'gamma'], $this->names($widget));

        $widget->sortBy('qty', SortDirection::Asc);
        self::assertSame(['gamma', 'alpha'], $this->names($widget));
    }

    public function testFilteringResetsTheCursorAndRowEventsFollowTheVisibleList(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(2);

        $widget->setFilter('MIT');
        self::assertSame(0, $widget->getSelectedIndex());

        $seen = [];
        $widget->onRowChange(static function (RowChangeEvent $event) use (&$seen): void {
            $seen[] = [$event->index, $event->row['name']];
        });

        $widget->handleInput(self::DOWN);
        $widget->handleInput(self::DOWN); // only two matches, so this is a no-op

        self::assertSame([[1, 'gamma']], $seen);
        self::assertSame(1, $widget->getSelectedIndex());
    }

    public function testFilterChangeCarriesTheMatchCountAndFiresOnlyOnChange(): void
    {
        $widget = $this->table();
        $counts = [];
        $widget->onFilterChange(static function (FilterChangeEvent $event) use (&$counts): void {
            $counts[] = $event->matchCount;
        });

        $widget->setFilter('MIT');
        $widget->setFilter('MIT');  // same filter, no event
        $widget->setFilter('nope'); // matches nothing
        $widget->clearFilter();
        $widget->clearFilter();     // already cleared, no event

        self::assertSame([2, 0, 3], $counts);
    }

    public function testEmptyResultSaysNoMatchesWhileEmptyDataSaysNoRows(): void
    {
        $widget = $this->table();
        $widget->setFilter('nope');

        $lines = $widget->render(new RenderContext(40, 24));
        self::assertCount(2, $lines);
        self::assertSame('No matches', trim(AnsiUtils::stripAnsiCodes($lines[1])));

        $widget->clearFilter();
        $widget->setRows([]);

        $lines = $widget->render(new RenderContext(40, 24));
        self::assertSame('No rows', trim(AnsiUtils::stripAnsiCodes($lines[1])));
    }

    public function testFilteredTableRendersOnlyMatchesAndCountsThemInTheIndicator(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = ['name' => \sprintf('package-%d', $i)];
        }

        $widget = new TableWidget([new Column('name', 'Package')], $rows, maxVisible: 3);
        $widget->setFilter('package-1');

        $lines = $widget->render(new RenderContext(40, 24));
        $text = array_map(static fn (string $l): string => rtrim(AnsiUtils::stripAnsiCodes($l)), $lines);

        // package-1 plus package-10..19 is 11 matches, 3 of them on screen.
        self::assertSame('package-1', $text[1]);
        self::assertSame('package-10', $text[2]);
        self::assertSame('package-11', $text[3]);
        self::assertSame('(1/11)', trim($text[4]));
    }

    private function table(): TableWidget
    {
        return new TableWidget(
            [
                new Column('name', 'Package'),
                new Column('qty', 'Qty'),
                new Column('license', 'License', sortable: false),
            ],
            [
                ['name' => 'alpha', 'qty' => 9, 'license' => 'MIT'],
                ['name' => 'beta', 'qty' => 5, 'license' => 'BSD'],
                ['name' => 'gamma', 'qty' => 1, 'license' => 'MIT'],
            ],
        );
    }

    /**
     * Names of the currently visible rows, in order.
     *
     * The count comes from setSelectedIndex() clamping — the only handle the
     * public API gives us on the size of the visible list.
     *
     * @return list<mixed>
     */
    private function names(TableWidget $widget): array
    {
        $names = [];
        $count = $widget->setSelectedIndex(\PHP_INT_MAX)->getSelectedIndex() + 1;

        for ($i = 0; $i < $count; ++$i) {
            $row = $widget->setSelectedIndex($i)->getSelectedRow();

            if (null === $row) {
                break;
            }

            $names[] = $row['name'] ?? null;
        }

        $widget->setSelectedIndex(0);

        return $names;
    }
}
