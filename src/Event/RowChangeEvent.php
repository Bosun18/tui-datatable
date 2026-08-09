<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Event;

use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * The cursor moved to another row.
 *
 * Fires on navigation, not on confirmation — that is {@see RowSelectEvent}. A
 * key that leaves the cursor where it was, such as Up on the first row, fires
 * nothing.
 */
final class RowChangeEvent extends AbstractEvent
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(
        TableWidget $target,
        public readonly array $row,
        public readonly int $index,
    ) {
        parent::__construct($target);
    }
}
