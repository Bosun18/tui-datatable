<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\FilterChangeEvent;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\StyleSheet;

#[CoversClass(TableWidget::class)]
final class TableWidgetStateTest extends TestCase
{
    public function testSelectionStartsAtTheFirstRow(): void
    {
        $widget = $this->table();

        self::assertSame(0, $widget->getSelectedIndex());
        self::assertSame(['name' => 'alpha'], $widget->getSelectedRow());
    }

    public function testSelectedRowIsNullWithoutRows(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')]);

        self::assertNull($widget->getSelectedRow());
        self::assertSame(0, $widget->getSelectedIndex());
    }

    public function testSelectedIndexIsClampedToTheAvailableRows(): void
    {
        $widget = $this->table();

        $widget->setSelectedIndex(99);
        self::assertSame(2, $widget->getSelectedIndex());

        $widget->setSelectedIndex(-5);
        self::assertSame(0, $widget->getSelectedIndex());
    }

    public function testStateChangesBumpTheRenderRevision(): void
    {
        $widget = $this->table();
        $before = $widget->getRenderRevision();

        $widget->setSelectedIndex(1);
        $afterSelect = $widget->getRenderRevision();
        self::assertGreaterThan($before, $afterSelect);

        $widget->setRows([['name' => 'delta']]);
        self::assertGreaterThan($afterSelect, $widget->getRenderRevision());
    }

    public function testSettingTheSameIndexDoesNotInvalidate(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(1);
        $revision = $widget->getRenderRevision();

        $widget->setSelectedIndex(1);

        self::assertSame($revision, $widget->getRenderRevision());
    }

    public function testNewRowsResetTheSelection(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(2);

        $widget->setRows([['name' => 'delta'], ['name' => 'epsilon']]);

        self::assertSame(0, $widget->getSelectedIndex());
        self::assertSame(['name' => 'delta'], $widget->getSelectedRow());
    }

    public function testVerticalExpansionIsOffByDefaultAndTogglesCleanly(): void
    {
        $widget = $this->table();

        self::assertFalse($widget->isVerticallyExpanded());

        $before = $widget->getRenderRevision();
        $widget->expandVertically(true);
        self::assertTrue($widget->isVerticallyExpanded());
        self::assertGreaterThan($before, $widget->getRenderRevision());

        $revision = $widget->getRenderRevision();
        $widget->expandVertically(true);
        self::assertSame($revision, $widget->getRenderRevision(), 'setting the same value is a no-op');

        $widget->expandVertically(false);
        self::assertFalse($widget->isVerticallyExpanded());
    }

    public function testSetMaxVisibleInvalidatesOnlyOnChange(): void
    {
        $widget = $this->table();

        $before = $widget->getRenderRevision();
        $widget->setMaxVisible(4);
        self::assertGreaterThan($before, $widget->getRenderRevision());

        $revision = $widget->getRenderRevision();
        $widget->setMaxVisible(4);
        self::assertSame($revision, $widget->getRenderRevision());
    }

    public function testSetMaxVisibleRejectsLessThanOneRow(): void
    {
        $widget = $this->table();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxVisible must be at least 1, got 0.');

        $widget->setMaxVisible(0);
    }

    public function testGetColumnsHandsBackTheColumnsInOrder(): void
    {
        $columns = [
            new Column('name', 'Package'),
            new Column('qty', 'Qty', sortable: false),
        ];
        $widget = new TableWidget($columns, []);

        self::assertSame($columns, $widget->getColumns());

        // Enough to build the 'sorted by <header>' label a caller needs.
        $headers = [];
        foreach ($widget->getColumns() as $column) {
            $headers[$column->key] = $column->header;
        }
        self::assertSame(['name' => 'Package', 'qty' => 'Qty'], $headers);
    }

    public function testVisibleRowCountFollowsFilterAndData(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package'), new Column('license', 'License')],
            [
                ['name' => 'alpha', 'license' => 'MIT'],
                ['name' => 'beta', 'license' => 'BSD'],
                ['name' => 'gamma', 'license' => 'MIT'],
            ],
        );

        self::assertSame(3, $widget->getVisibleRowCount());

        $widget->setFilter('MIT');
        self::assertSame(2, $widget->getVisibleRowCount());

        $widget->setFilter('nope');
        self::assertSame(0, $widget->getVisibleRowCount());

        $widget->clearFilter();
        self::assertSame(3, $widget->getVisibleRowCount());

        $widget->setRows([]);
        self::assertSame(0, $widget->getVisibleRowCount());
    }

    public function testVisibleRowCountMatchesTheFilterChangeEvent(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package')],
            [['name' => 'alpha'], ['name' => 'beta']],
        );

        $reported = null;
        $widget->onFilterChange(static function (FilterChangeEvent $event) use (&$reported): void {
            $reported = $event->matchCount;
        });

        $widget->setFilter('a');

        self::assertSame($reported, $widget->getVisibleRowCount());
    }

    public function testDefaultStyleSheetCoversEveryElementTheWidgetStyles(): void
    {
        $rules = TableWidget::defaultStyleSheet()->getRules();

        self::assertSame([
            TableWidget::class.'::header',
            TableWidget::class.'::header-cursor',
            TableWidget::class.'::header-sorted',
            TableWidget::class.'::selected',
            TableWidget::class.'::row-alt',
            TableWidget::class.'::scroll-info',
            TableWidget::class.'::no-match',
        ], array_keys($rules));

        self::assertInstanceOf(StyleSheet::class, TableWidget::defaultStyleSheet());
    }

    private function table(): TableWidget
    {
        return new TableWidget(
            [new Column('name', 'Package')],
            [['name' => 'alpha'], ['name' => 'beta'], ['name' => 'gamma']],
        );
    }
}
