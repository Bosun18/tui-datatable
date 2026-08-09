<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\RowChangeEvent;
use Bosun18\TuiDataTable\Event\RowSelectEvent;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * handleInput() takes raw terminal bytes, not key names, so these tests feed
 * escape sequences directly.
 */
#[CoversClass(TableWidget::class)]
#[CoversClass(RowChangeEvent::class)]
#[CoversClass(RowSelectEvent::class)]
final class TableWidgetInputTest extends TestCase
{
    private const string DOWN = "\e[B";
    private const string UP = "\e[A";
    private const string PAGE_UP = "\e[5~";
    private const string PAGE_DOWN = "\e[6~";
    private const string HOME = "\e[H";
    private const string END = "\e[F";
    private const string ENTER = "\r";
    private const string ESCAPE = "\e";
    private const string CTRL_C = "\x03";

    public function testDownMovesTheCursorAndAnnouncesTheNewRow(): void
    {
        $widget = $this->table();
        $seen = [];
        $widget->onRowChange(static function (RowChangeEvent $event) use (&$seen): void {
            $seen[] = [$event->index, $event->row['name']];
        });

        $widget->handleInput(self::DOWN);
        $widget->handleInput(self::DOWN);

        self::assertSame(2, $widget->getSelectedIndex());
        self::assertSame([[1, 'beta'], [2, 'gamma']], $seen);
    }

    public function testUpAtTheFirstRowStaysPutAndSaysNothing(): void
    {
        $widget = $this->table();
        $widget->onRowChange(static function (RowChangeEvent $event): void {
            self::fail('no row change expected at the top edge');
        });

        $widget->handleInput(self::UP);

        self::assertSame(0, $widget->getSelectedIndex());
    }

    public function testDownAtTheLastRowStaysPut(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(3);
        $changes = 0;
        $widget->onRowChange(static function (RowChangeEvent $event) use (&$changes): void {
            ++$changes;
        });

        $widget->handleInput(self::DOWN);

        self::assertSame(3, $widget->getSelectedIndex());
        self::assertSame(0, $changes);
    }

    public function testPageKeysMoveByTheWindowAndClampOnAShortList(): void
    {
        // 4 rows, window of 2
        $widget = $this->table(maxVisible: 2);

        $widget->handleInput(self::PAGE_DOWN);
        self::assertSame(2, $widget->getSelectedIndex());

        $widget->handleInput(self::PAGE_DOWN);
        self::assertSame(3, $widget->getSelectedIndex(), 'clamped to the last row');

        $widget->handleInput(self::PAGE_UP);
        self::assertSame(1, $widget->getSelectedIndex());

        $widget->handleInput(self::PAGE_UP);
        self::assertSame(0, $widget->getSelectedIndex(), 'clamped to the first row');
    }

    public function testHomeAndEndJumpToTheEdges(): void
    {
        $widget = $this->table();

        $widget->handleInput(self::END);
        self::assertSame(3, $widget->getSelectedIndex());

        $widget->handleInput(self::HOME);
        self::assertSame(0, $widget->getSelectedIndex());
    }

    public function testEnterCarriesTheSelectedRowAndItsIndex(): void
    {
        $widget = $this->table();
        $widget->setSelectedIndex(2);
        $selected = null;
        $widget->onRowSelect(static function (RowSelectEvent $event) use (&$selected): void {
            $selected = $event;
        });

        $widget->handleInput(self::ENTER);

        self::assertInstanceOf(RowSelectEvent::class, $selected);
        self::assertSame(2, $selected->index);
        self::assertSame(['name' => 'gamma'], $selected->row);
        self::assertSame($widget, $selected->getTarget());
    }

    public function testEscapeAndCtrlCCancel(): void
    {
        foreach ([self::ESCAPE, self::CTRL_C] as $bytes) {
            $widget = $this->table();
            $cancelled = 0;
            $widget->onCancel(static function (CancelEvent $event) use (&$cancelled): void {
                ++$cancelled;
            });

            $widget->handleInput($bytes);

            self::assertSame(1, $cancelled, 'cancel expected for '.json_encode($bytes));
        }
    }

    public function testMovingTheCursorInvalidatesTheRenderCache(): void
    {
        $widget = $this->table();
        $before = $widget->getRenderRevision();

        $widget->handleInput(self::DOWN);

        self::assertGreaterThan($before, $widget->getRenderRevision());
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $widget = $this->table();
        $before = $widget->getRenderRevision();

        $widget->handleInput('z');

        self::assertSame(0, $widget->getSelectedIndex());
        self::assertSame($before, $widget->getRenderRevision());
    }

    public function testWithoutRowsNavigationAndEnterDoNothingWhileCancelStillWorks(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')]);
        $events = [];
        $widget
            ->onRowChange(static function (RowChangeEvent $event) use (&$events): void {
                $events[] = 'change';
            })
            ->onRowSelect(static function (RowSelectEvent $event) use (&$events): void {
                $events[] = 'select';
            })
            ->onCancel(static function (CancelEvent $event) use (&$events): void {
                $events[] = 'cancel';
            });

        $widget->handleInput(self::DOWN);
        $widget->handleInput(self::END);
        $widget->handleInput(self::ENTER);
        $widget->handleInput(self::ESCAPE);

        self::assertSame(['cancel'], $events);
        self::assertSame(0, $widget->getSelectedIndex());
    }

    public function testOnInputHookCanSwallowAKey(): void
    {
        $widget = $this->table();
        $widget->onInput(static fn (string $data): bool => self::DOWN === $data);

        $widget->handleInput(self::DOWN);
        self::assertSame(0, $widget->getSelectedIndex(), 'the hook consumed the key');

        $widget->handleInput(self::END);
        self::assertSame(3, $widget->getSelectedIndex(), 'other keys still reach the widget');
    }

    public function testCustomKeybindingsFromTheConstructorReplaceTheDefaults(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package')],
            [['name' => 'alpha'], ['name' => 'beta']],
            2,
            new Keybindings(['row_down' => ['j']]),
        );

        $widget->handleInput('j');

        self::assertSame(1, $widget->getSelectedIndex());
    }

    public function testTheWidgetIsFocusable(): void
    {
        $widget = $this->table();

        self::assertFalse($widget->isFocused());
        $widget->setFocused(true);
        self::assertTrue($widget->isFocused());
    }

    private function table(int $maxVisible = 10): TableWidget
    {
        return new TableWidget(
            [new Column('name', 'Package')],
            [
                ['name' => 'alpha'],
                ['name' => 'beta'],
                ['name' => 'gamma'],
                ['name' => 'delta'],
            ],
            $maxVisible,
        );
    }
}
