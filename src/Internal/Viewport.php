<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Internal;

/**
 * Scroll window arithmetic, kept apart from rendering so it can be tested on
 * its own.
 *
 * The window follows the cursor and keeps it centred, which is what
 * SelectListWidget does upstream.
 *
 * @internal
 */
final class Viewport
{
    /**
     * Index of the first visible row.
     */
    public static function start(int $cursor, int $total, int $maxVisible): int
    {
        $maxVisible = max(1, $maxVisible);

        if ($total <= $maxVisible) {
            return 0;
        }

        // Centre the cursor, then pull the window back so the last page is
        // still full instead of trailing off past the final row.
        return max(0, min($cursor - intdiv($maxVisible, 2), $total - $maxVisible));
    }

    /**
     * How many rows the window shows.
     */
    public static function length(int $total, int $maxVisible): int
    {
        return max(0, min($total, max(1, $maxVisible)));
    }
}
