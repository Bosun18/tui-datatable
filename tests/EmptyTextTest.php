<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

#[CoversClass(TableWidget::class)]
final class EmptyTextTest extends TestCase
{
    public function testDefaultsAreUnchanged(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);
        self::assertSame('No rows', self::secondLine($widget));

        $widget->setRows([['name' => 'alpha']])->setFilter('nope');
        self::assertSame('No matches', self::secondLine($widget));
    }

    public function testEmptyTextReplacesTheNoDataLine(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);

        $widget->setEmptyText('Пока нет данных');

        self::assertSame('Пока нет данных', self::secondLine($widget));
    }

    public function testNoMatchTextIsUsedWhileAFilterIsActive(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], [['name' => 'alpha']]);
        $widget->setEmptyText('Пока нет данных')->setNoMatchText('Ничего не найдено');

        $widget->setFilter('nope');
        self::assertSame('Ничего не найдено', self::secondLine($widget), 'a filter that matched nothing');

        $widget->clearFilter();
        $widget->setRows([]);
        self::assertSame('Пока нет данных', self::secondLine($widget), 'no data at all');
    }

    public function testAFilterMatchingNothingPrefersNoMatchEvenWithoutRows(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);
        $widget->setNoMatchText('Ничего не найдено');

        $widget->setFilter('anything');

        self::assertSame('Ничего не найдено', self::secondLine($widget));
    }

    public function testBothSettersInvalidateOnlyOnChange(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);

        $before = $widget->getRenderRevision();
        $widget->setEmptyText('Пусто');
        self::assertGreaterThan($before, $widget->getRenderRevision());

        $revision = $widget->getRenderRevision();
        $widget->setEmptyText('Пусто');
        self::assertSame($revision, $widget->getRenderRevision());

        $widget->setNoMatchText('Нет совпадений');
        self::assertGreaterThan($revision, $widget->getRenderRevision());

        $revision = $widget->getRenderRevision();
        $widget->setNoMatchText('Нет совпадений');
        self::assertSame($revision, $widget->getRenderRevision());
    }

    public function testAnEmptyStringIsAllowedAndDrawsABlankLine(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);

        $widget->setEmptyText('');

        $lines = $widget->render(new RenderContext(40, 24));
        self::assertCount(2, $lines);
        self::assertSame('', self::secondLine($widget));
    }

    public function testLongTextIsClippedToTheTerminal(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);
        $widget->setEmptyText(str_repeat('очень длинный текст ', 10));

        $lines = $widget->render(new RenderContext(20, 24));

        foreach ($lines as $line) {
            self::assertLessThanOrEqual(20, AnsiUtils::visibleWidth($line));
        }
    }

    private static function secondLine(TableWidget $widget): string
    {
        $lines = $widget->render(new RenderContext(40, 24));

        return trim(AnsiUtils::stripAnsiCodes($lines[1] ?? ''));
    }
}
