<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Column::class)]
final class ColumnTest extends TestCase
{
    public function testKeyAndHeaderAreEnoughToBuildAColumn(): void
    {
        $column = new Column('name', 'Package');

        self::assertSame('name', $column->key);
        self::assertSame('Package', $column->header);
        self::assertNull($column->width);
        self::assertSame(Align::Left, $column->align);
        self::assertTrue($column->sortable);
        self::assertNull($column->formatter);
        self::assertNull($column->comparator);
    }

    public function testEveryArgumentIsStoredAsGiven(): void
    {
        $formatter =
            /** @param array<string, mixed> $row */
            static fn (mixed $value, array $row): string => 'formatted';
        $comparator = static fn (mixed $a, mixed $b): int => $a <=> $b;

        $column = new Column(
            key: 'downloads',
            header: 'Downloads',
            width: 12,
            align: Align::Right,
            sortable: false,
            formatter: $formatter,
            comparator: $comparator,
        );

        self::assertSame('downloads', $column->key);
        self::assertSame('Downloads', $column->header);
        self::assertSame(12, $column->width);
        self::assertSame(Align::Right, $column->align);
        self::assertFalse($column->sortable);
        self::assertSame($formatter, $column->formatter);
        self::assertSame($comparator, $column->comparator);
    }
}
