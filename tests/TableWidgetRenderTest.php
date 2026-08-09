<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Snapshots compare rows after rtrim, because trailing padding is invisible in
 * a literal and would make these tests unreadable. Padding is still covered:
 * inner gaps stay in the snapshot, and every test asserts the rendered width.
 */
#[CoversClass(TableWidget::class)]
final class TableWidgetRenderTest extends TestCase
{
    /**
     * 25 columns is exactly what this data wants (name 10 + gap 2 + qty 3 +
     * gap 2 + license 8), so nothing is stretched and the layout shows through.
     */
    public function testHeaderAndRowsAtTheirNaturalWidth(): void
    {
        $lines = $this->table()->render(new RenderContext(25, 24));

        self::assertSame([
            'Package     Qty  License',
            'alpha         5  MIT',
            'beta         40  BSD',
            'gamma-long  700  MIT',
        ], self::visibleText($lines));

        foreach ($lines as $line) {
            self::assertSame(25, AnsiUtils::visibleWidth($line));
        }

        self::assertFitsWidth($lines, 25);
    }

    public function testFixedColumnKeepsItsWidthWhileAutoColumnsFollowContent(): void
    {
        $lines = $this->table()->render(new RenderContext(25, 24));
        $header = self::visibleText($lines)[0];

        // 'License' is pinned to 8 cells, so it starts at 10 + 2 + 3 + 2 = 17.
        self::assertSame('License', substr($header, 17));
        self::assertSame('Package', substr($header, 0, 7));
    }

    public function testSpareRoomGoesToTheLastAutoColumnAndRowsFillTheLine(): void
    {
        $lines = $this->table()->render(new RenderContext(60, 24));

        foreach ($lines as $index => $line) {
            self::assertSame(60, AnsiUtils::visibleWidth($line), "line {$index} does not fill the terminal");
        }

        // 'license' keeps its 8 cells at the right edge, so everything before it
        // — including the spare 35 cells — belongs to the last auto column.
        $header = self::visibleText($lines)[0];
        self::assertSame('License', substr($header, 52));
        self::assertSame('Package', substr($header, 0, 7));

        // 'qty' is right-aligned, so its value ends where 'license' begins.
        self::assertSame('700', substr(rtrim(substr(self::visibleText($lines)[3], 0, 50)), -3));
    }

    public function testFixedOnlyColumnsAreNotStretchedButLinesStillFillTheWidth(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package', width: 7), new Column('license', 'License', width: 8)],
            [['name' => 'alpha', 'license' => 'MIT']],
        );

        $lines = $widget->render(new RenderContext(40, 24));

        // Columns stay 7 + gap 2 + 8 = 17 wide; the rest of the line is padding
        // so that row styles still cover it.
        self::assertSame('alpha    MIT', self::visibleText($lines)[1]);
        self::assertSame(40, AnsiUtils::visibleWidth($lines[1]));
    }

    public function testNarrowTerminalShrinksAutoColumnsAndClipsTheLine(): void
    {
        $lines = $this->table()->render(new RenderContext(20, 24));

        // Auto columns fall to 'name' 6 and 'qty' 3 while 'license' keeps its 8,
        // which totals 21 with gaps, so the line loses its last cell to clipping.
        self::assertSame([
            'Packag  Qty  License',
            'alpha     5  MIT',
            'beta     40  BSD',
        ], self::visibleText([$lines[0], $lines[1], $lines[2]]));

        self::assertFitsWidth($lines, 20);
    }

    public function testFormatterProducesCellText(): void
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
            [['name' => 'alpha', 'qty' => 1234567]],
        );

        // 18 columns is the natural width here (name 7 + gap 2 + qty 9).
        $lines = $widget->render(new RenderContext(18, 24));

        // The formatted value is 9 cells wide, so the column is 9 and the
        // right-aligned header sits at its end.
        self::assertSame([
            'Package        Qty',
            'alpha    1,234,567',
        ], self::visibleText($lines));

        self::assertFitsWidth($lines, 18);
    }

    public function testScrollIndicatorAppearsOnlyWhenRowsAreHidden(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = ['name' => "row-{$i}"];
        }

        $widget = new TableWidget([new Column('name', 'Package')], $rows, maxVisible: 5);
        $lines = $widget->render(new RenderContext(40, 24));

        // header + 5 rows + indicator
        self::assertCount(7, $lines);
        self::assertSame('(1/30)', trim(self::visibleText($lines)[6]));
        self::assertSame('row-1', trim(self::visibleText($lines)[1]));
        self::assertSame('row-5', trim(self::visibleText($lines)[5]));

        self::assertFitsWidth($lines, 40);
    }

    public function testEmptyRowsRenderTheNoMatchLine(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);
        $lines = $widget->render(new RenderContext(40, 24));

        self::assertCount(2, $lines);
        self::assertSame('Package', trim(self::visibleText($lines)[0]));
        self::assertSame('No rows', trim(self::visibleText($lines)[1]));

        self::assertFitsWidth($lines, 40);
    }

    /**
     * Upstream counts a ZWJ family as 11 cells even though terminals draw about
     * two, so expectations here come from visibleWidth(), never from intuition.
     */
    public function testMultibyteAndEmojiCellsRespectTheColumnWidth(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package', width: 6), new Column('note', 'Note', width: 4)],
            [
                ['name' => 'кириллица', 'note' => '👨‍👩‍👧‍👦'],
                ['name' => '日本語', 'note' => 'ok'],
            ],
        );

        $lines = $widget->render(new RenderContext(30, 24));

        self::assertFitsWidth($lines, 30);

        // Both columns are pinned (6 and 4), so the cells occupy 12 cells and
        // the rest of each line is padding.
        self::assertSame('Packag  Note', self::visibleText($lines)[0]);
        foreach ($lines as $line) {
            self::assertSame(30, AnsiUtils::visibleWidth($line));
        }
    }

    public function testCentreAlignmentSplitsThePaddingLeavingTheOddCellOnTheRight(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package', width: 9, align: Align::Center)],
            [['name' => 'ab']],
        );

        $lines = $widget->render(new RenderContext(20, 24));

        // 9 - 7 = 2 spare cells for the header, split one and one.
        self::assertSame(' Package', self::visibleText($lines)[0]);
        // 9 - 2 = 7 spare cells for 'ab': three on the left, four on the right.
        self::assertSame('   ab', self::visibleText($lines)[1]);
        self::assertSame(20, AnsiUtils::visibleWidth($lines[1]), 'the line is padded to the terminal width');

        self::assertFitsWidth($lines, 20);
    }

    public function testMissingKeysAndUnprintableValuesDegradeGracefully(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package'), new Column('meta', 'Meta')],
            [
                ['name' => 'alpha'],
                ['name' => 'beta', 'meta' => ['nested' => true]],
                ['name' => 'gamma', 'meta' => new \DateTimeImmutable('2026-08-09')],
            ],
        );

        $lines = self::visibleText($widget->render(new RenderContext(40, 24)));

        // 'name' is 7 cells wide (its header), so every value is padded to 7
        // and then separated by the 2-cell gap.
        self::assertSame('alpha', $lines[1], 'a missing key renders as an empty cell');
        self::assertSame('beta     array', $lines[2], 'an array shows its type instead of crashing');
        self::assertSame('gamma    DateTimeImmutable', $lines[3]);
    }

    public function testStringableValuesUseTheirStringForm(): void
    {
        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $widget = new TableWidget(
            [new Column('name', 'Package')],
            [['name' => $value]],
        );

        self::assertSame('stringable', self::visibleText($widget->render(new RenderContext(40, 24)))[1]);
    }

    public function testSortedColumnGetsAnArrowThatCountsTowardsItsWidth(): void
    {
        $widget = $this->table();

        $widget->sortBy('qty', SortDirection::Asc);
        $header = self::visibleText($widget->render(new RenderContext(25, 24)))[0];
        self::assertStringContainsString('Qty ↑', $header);
        self::assertSame(1, AnsiUtils::visibleWidth('↑'), 'the arrow is one cell wide');

        $widget->sortBy('qty', SortDirection::Desc);
        self::assertStringContainsString('Qty ↓', self::visibleText($widget->render(new RenderContext(25, 24)))[0]);

        $widget->clearSort();
        $header = self::visibleText($widget->render(new RenderContext(25, 24)))[0];
        self::assertStringNotContainsString('↑', $header);
        self::assertStringNotContainsString('↓', $header);
    }

    public function testTheArrowWidensTheColumnRatherThanOverflowingTheLine(): void
    {
        $widget = new TableWidget(
            [new Column('name', 'Package'), new Column('qty', 'Qty')],
            [['name' => 'alpha', 'qty' => 1]],
        );

        // Natural width without an arrow: name 7 + gap 2 + qty 3 = 12.
        self::assertSame(12, AnsiUtils::visibleWidth($widget->render(new RenderContext(12, 24))[0]));

        $widget->sortBy('qty', SortDirection::Asc);
        $lines = $widget->render(new RenderContext(14, 24));

        // 'Qty ↑' needs 5 cells, so the natural width grows to 14.
        self::assertSame('Package  Qty ↑', self::visibleText($lines)[0]);
        self::assertFitsWidth($lines, 14);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function terminalHeights(): iterable
    {
        // available rows, expected line count
        yield 'plenty of room' => [24, 17];   // header + 15 rows + indicator
        yield 'ten rows' => [10, 10];
        yield 'five rows' => [5, 5];
        yield 'two rows' => [2, 2];
        yield 'one row leaves the header only' => [1, 1];
        yield 'no room at all' => [0, 0];
    }

    #[DataProvider('terminalHeights')]
    public function testRenderNeverReturnsMoreLinesThanTheContextAllows(int $available, int $expected): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 15);

        $lines = $widget->render(new RenderContext(40, $available));

        self::assertCount($expected, $lines);
    }

    public function testTheHeaderSurvivesAShortTerminal(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 15);

        foreach ([1, 2, 5, 10] as $available) {
            $lines = self::visibleText($widget->render(new RenderContext(40, $available)));

            self::assertSame('Package', $lines[0], "header lost at {$available} rows");
        }
    }

    public function testTheScrollIndicatorIsDroppedWhenThereIsNoRoomForIt(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 15);

        // 10 rows: header, 8 data rows, indicator.
        $roomy = self::visibleText($widget->render(new RenderContext(40, 10)));
        self::assertSame('(1/30)', trim($roomy[9]));
        self::assertSame('row-8', $roomy[8]);

        // 2 rows: header and a single data row, no room to spare.
        $tight = self::visibleText($widget->render(new RenderContext(40, 2)));
        self::assertSame('row-1', $tight[1]);
    }

    public function testTheCursorStaysVisibleInAShortTerminal(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 15);
        $widget->setSelectedIndex(29);

        $lines = self::visibleText($widget->render(new RenderContext(40, 5)));

        // header + 3 rows + indicator, and the last row has to be among them
        self::assertCount(5, $lines);
        self::assertSame('row-30', $lines[3]);
        self::assertSame('(30/30)', trim($lines[4]));
    }

    public function testEmptyStateAlsoRespectsTheHeight(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], []);

        self::assertCount(2, $widget->render(new RenderContext(40, 24)));
        self::assertCount(2, $widget->render(new RenderContext(40, 2)));
        self::assertCount(1, $widget->render(new RenderContext(40, 1)), 'only the header fits');
        self::assertCount(0, $widget->render(new RenderContext(40, 0)));
    }

    public function testWithoutExpansionMaxVisibleDecidesTheWindow(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);

        // header + 5 rows + indicator, even though 24 rows were offered
        self::assertCount(7, $widget->render(new RenderContext(40, 24)));
    }

    public function testExpansionIgnoresMaxVisibleAndTakesTheOfferedHeight(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);
        $widget->expandVertically(true);

        // header + 22 rows + indicator fills all 24 rows
        $lines = $widget->render(new RenderContext(40, 24));
        self::assertCount(24, $lines);
        self::assertSame('row-22', self::visibleText($lines)[22]);
        self::assertSame('(1/30)', trim(self::visibleText($lines)[23]));

        // and it still respects a short terminal
        self::assertCount(8, $widget->render(new RenderContext(40, 8)));
    }

    public function testExpansionFollowsAResize(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);
        $widget->expandVertically(true);

        $counts = [];
        foreach ([30, 12, 40, 3] as $available) {
            $counts[$available] = \count($widget->render(new RenderContext(40, $available)));
        }

        self::assertSame([30 => 30, 12 => 12, 40 => 31, 3 => 3], $counts, 'at 40 rows only 30 rows of data exist');
    }

    public function testSetMaxVisibleMovesTheWindowWhileExpansionIsOff(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);

        $widget->setMaxVisible(3);

        self::assertCount(5, $widget->render(new RenderContext(40, 24)), 'header + 3 rows + indicator');
    }

    public function testSetMaxVisibleChangesNothingWhileExpansionIsOn(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);
        $widget->expandVertically(true)->setMaxVisible(3);

        self::assertCount(24, $widget->render(new RenderContext(40, 24)));
    }

    public function testPagingUsesTheRenderedWindowWhenExpanded(): void
    {
        $widget = new TableWidget([new Column('name', 'Package')], self::manyRows(), maxVisible: 5);
        $widget->expandVertically(true);

        // Render first so the widget knows how tall it actually is: 12 rows
        // means a window of 10 data rows.
        $widget->render(new RenderContext(40, 12));
        $widget->handleInput("\e[6~");

        self::assertSame(10, $widget->getSelectedIndex(), 'a page is the window that was drawn, not maxVisible');
    }

    public function testEveryLineStaysWithinASingleColumnTerminal(): void
    {
        $lines = $this->table()->render(new RenderContext(1, 24));

        self::assertFitsWidth($lines, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function manyRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = ['name' => "row-{$i}"];
        }

        return $rows;
    }

    private function table(): TableWidget
    {
        return new TableWidget(
            [
                new Column('name', 'Package'),
                new Column('qty', 'Qty', align: Align::Right),
                new Column('license', 'License', width: 8),
            ],
            [
                ['name' => 'alpha', 'qty' => 5, 'license' => 'MIT'],
                ['name' => 'beta', 'qty' => 40, 'license' => 'BSD'],
                ['name' => 'gamma-long', 'qty' => 700, 'license' => 'MIT'],
            ],
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function visibleText(array $lines): array
    {
        return array_map(
            static fn (string $line): string => rtrim(AnsiUtils::stripAnsiCodes($line)),
            $lines,
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function assertFitsWidth(array $lines, int $columns): void
    {
        foreach ($lines as $index => $line) {
            self::assertLessThanOrEqual(
                $columns,
                AnsiUtils::visibleWidth($line),
                "line {$index} is wider than the terminal",
            );
            self::assertStringNotContainsString("\n", $line, "line {$index} contains a newline");
        }
    }
}
