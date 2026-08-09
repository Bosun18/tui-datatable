<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Event;

use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * A row was confirmed with Enter.
 */
final class RowSelectEvent extends AbstractEvent
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
