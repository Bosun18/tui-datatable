<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\RowChangeEvent;
use Bosun18\TuiDataTable\Event\SortChangeEvent;
use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableWidget::class)]
#[CoversClass(SortChangeEvent::class)]
final class SortingTest extends TestCase
{
    private const string LEFT = "\e[D";
    private const string RIGHT = "\e[C";
    private const string SORT = 's';
    private const string DOWN = "\e[B";

    public function testSortByOrdersRowsAscendingAndDescending(): void
    {
        $widget = $this->table();

        $widget->sortBy('qty', SortDirection::Asc);
        self::assertSame([1, 5, 5, 9], $this->column($widget, 'qty'));

        $widget->sortBy('qty', SortDirection::Desc);
        self::assertSame([9, 5, 5, 1], $this->column($widget, 'qty'));
    }

    public function testClearSortRestoresTheOriginalOrder(): void
    {
        $widget = $this->table();
        $original = $this->column($widget, 'name');

        $widget->sortBy('qty', SortDirection::Asc);
        self::assertNotSame($original, $this->column($widget, 'name'));

        $widget->clearSort();
        self::assertSame($original, $this->column($widget, 'name'));
    }

    public function testEqualValuesKeepTheirOriginalOrder(): void
    {
        $widget = $this->table();

        $widget->sortBy('qty', SortDirection::Asc);

        // 'beta' and 'delta' both hold 5 and were given in that order.
        self::assertSame(['gamma', 'beta', 'delta', 'alpha'], $this->column($widget, 'name'));
    }

    public function testDescendingKeepsEqualValuesInTheirOriginalOrderToo(): void
    {
        $widget = $this->table();

        $widget->sortBy('qty', SortDirection::Desc);

        self::assertSame(['alpha', 'beta', 'delta', 'gamma'], $this->column($widget, 'name'));
    }

    public function testCustomComparatorWins(): void
    {
        $numeric = static fn (mixed $value): int => (int) (\is_scalar($value) ? $value : 0);

        $widget = new TableWidget(
            [
                new Column('name', 'Package'),
                new Column(
                    'size',
                    'Size',
                    // '10kb' sorts after '9kb' only if compared numerically.
                    comparator: static fn (mixed $a, mixed $b): int => $numeric($a) <=> $numeric($b),
                ),
            ],
            [
                ['name' => 'alpha', 'size' => '10kb'],
                ['name' => 'beta', 'size' => '9kb'],
            ],
        );

        $widget->sortBy('size', SortDirection::Asc);

        self::assertSame(['beta', 'alpha'], $this->column($widget, 'name'));
    }

    public function testGetSortReportsTheCurrentStateAndNullWhenCleared(): void
    {
        $widget = $this->table();

        self::assertNull($widget->getSort());

        $widget->sortBy('qty', SortDirection::Desc);
        self::assertSame(['key' => 'qty', 'direction' => SortDirection::Desc], $widget->getSort());

        $widget->clearSort();
        self::assertNull($widget->getSort());
    }

    public function testSortByAnUnsortableColumnIsRejected(): void
    {
        $widget = $this->table();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Column "license" is not sortable.');

        $widget->sortBy('license', SortDirection::Asc);
    }

    public function testSortByAnUnknownColumnIsRejected(): void
    {
        $widget = $this->table();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no column with key "nope".');

        $widget->sortBy('nope', SortDirection::Asc);
    }

    public function testSortKeyCyclesAscendingDescendingAndOff(): void
    {
        $widget = $this->table();
        $widget->handleInput(self::RIGHT); // cursor onto 'qty'

        $widget->handleInput(self::SORT);
        self::assertSame(['key' => 'qty', 'direction' => SortDirection::Asc], $widget->getSort());

        $widget->handleInput(self::SORT);
        self::assertSame(['key' => 'qty', 'direction' => SortDirection::Desc], $widget->getSort());

        $widget->handleInput(self::SORT);
        self::assertNull($widget->getSort());
    }

    public function testSortKeyOnAnUnsortableColumnDoesNothing(): void
    {
        $widget = $this->table();
        $widget->handleInput(self::RIGHT);
        $widget->handleInput(self::RIGHT); // cursor onto 'license'

        $widget->handleInput(self::SORT);

        self::assertNull($widget->getSort());
    }

    public function testTheColumnCursorStopsAtBothEnds(): void
    {
        $widget = $this->table();

        $widget->handleInput(self::LEFT);
        $widget->handleInput(self::SORT);
        self::assertSame('name', $widget->getSort()['key'] ?? null, 'still on the first column');

        $widget->clearSort();
        for ($i = 0; $i < 5; ++$i) {
            $widget->handleInput(self::RIGHT);
        }
        $widget->handleInput(self::SORT);
        self::assertNull($widget->getSort(), 'stopped on the unsortable last column');
    }

    public function testSortingResetsTheCursorToTheFirstRow(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(3);

        $widget->sortBy('qty', SortDirection::Asc);

        self::assertSame(0, $widget->getSelectedIndex());
        self::assertSame(['name' => 'gamma', 'qty' => 1, 'license' => 'MIT'], $widget->getSelectedRow());
    }

    public function testSortChangeIsAnnouncedOnlyWhenTheStateMoves(): void
    {
        $widget = $this->table();
        $seen = [];
        $widget->onSortChange(static function (SortChangeEvent $event) use (&$seen): void {
            $seen[] = [$event->key, $event->direction];
        });

        $widget->sortBy('qty', SortDirection::Asc);
        $widget->sortBy('qty', SortDirection::Asc); // same state, no event
        $widget->sortBy('qty', SortDirection::Desc);
        $widget->clearSort();
        $widget->clearSort(); // already cleared, no event

        self::assertSame([
            ['qty', SortDirection::Asc],
            ['qty', SortDirection::Desc],
            [null, null],
        ], $seen);
    }

    public function testRowEventsUseIndexesOfTheSortedList(): void
    {
        $widget = $this->table();
        $widget->sortBy('qty', SortDirection::Asc);
        $seen = [];
        $widget->onRowChange(static function (RowChangeEvent $event) use (&$seen): void {
            $seen[] = [$event->index, $event->row['name']];
        });

        $widget->handleInput(self::DOWN);

        self::assertSame([[1, 'beta']], $seen);
    }

    public function testMissingKeysSortAsNull(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package'), new Column('qty', 'Qty')],
            [
                ['name' => 'alpha', 'qty' => 2],
                ['name' => 'beta'],
                ['name' => 'gamma', 'qty' => 1],
            ],
        );

        $widget->sortBy('qty', SortDirection::Asc);

        // null <=> int puts the missing value first.
        self::assertSame(['beta', 'gamma', 'alpha'], $this->column($widget, 'name'));
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
                ['name' => 'delta', 'qty' => 5, 'license' => 'MIT'],
            ],
        );
    }

    /**
     * Reads one column out of the widget in its current visible order.
     *
     * The row count comes from setSelectedIndex() clamping, which is the only
     * thing the public API tells us about the size of the visible list.
     *
     * @return list<mixed>
     */
    private function column(TableWidget $widget, string $key): array
    {
        $values = [];
        $count = $widget->setSelectedIndex(\PHP_INT_MAX)->getSelectedIndex() + 1;

        for ($i = 0; $i < $count; ++$i) {
            $row = $widget->setSelectedIndex($i)->getSelectedRow();

            if (null === $row) {
                break;
            }

            $values[] = $row[$key] ?? null;
        }

        $widget->setSelectedIndex(0);

        return $values;
    }
}
