<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Column;
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

    public function testDefaultStyleSheetCoversEveryElementTheWidgetStyles(): void
    {
        $rules = TableWidget::defaultStyleSheet()->getRules();

        self::assertSame([
            TableWidget::class.'::header',
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
