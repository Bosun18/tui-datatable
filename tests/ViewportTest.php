<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Bosun18\TuiDataTable\Internal\Viewport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Viewport::class)]
final class ViewportTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function startCases(): iterable
    {
        // cursor, total, maxVisible, expected start
        yield 'no rows at all' => [0, 0, 10, 0];
        yield 'single row' => [0, 1, 10, 0];
        yield 'everything fits' => [3, 5, 10, 0];
        yield 'exactly fits' => [9, 10, 10, 0];
        yield 'cursor at first row' => [0, 30, 10, 0];
        yield 'cursor centred' => [15, 30, 10, 10];
        yield 'cursor at last row' => [29, 30, 10, 20];
        yield 'last page stays full' => [26, 30, 10, 20];
        yield 'window of one follows the cursor' => [5, 30, 1, 5];
        yield 'window of one at the last row' => [29, 30, 1, 29];
        yield 'maxVisible below one is treated as one' => [7, 30, 0, 7];
        yield 'even window rounds the centre down' => [10, 30, 4, 8];
    }

    #[DataProvider('startCases')]
    public function testStart(int $cursor, int $total, int $maxVisible, int $expected): void
    {
        self::assertSame($expected, Viewport::start($cursor, $total, $maxVisible));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function lengthCases(): iterable
    {
        // total, maxVisible, expected length
        yield 'no rows at all' => [0, 10, 0];
        yield 'single row' => [1, 10, 1];
        yield 'fewer rows than the window' => [4, 10, 4];
        yield 'more rows than the window' => [30, 10, 10];
        yield 'window of one' => [30, 1, 1];
        yield 'maxVisible below one is treated as one' => [30, 0, 1];
        yield 'no rows and no window' => [0, 0, 0];
    }

    #[DataProvider('lengthCases')]
    public function testLength(int $total, int $maxVisible, int $expected): void
    {
        self::assertSame($expected, Viewport::length($total, $maxVisible));
    }

    /**
     * The two helpers have to agree: a window must never run past the data.
     *
     * Reuses startCases, so it takes its fourth column too and ignores it.
     */
    #[DataProvider('startCases')]
    public function testWindowNeverRunsPastTheData(int $cursor, int $total, int $maxVisible, int $ignoredStart): void
    {
        $start = Viewport::start($cursor, $total, $maxVisible);
        $length = Viewport::length($total, $maxVisible);

        self::assertLessThanOrEqual($total, $start + $length);
    }
}
