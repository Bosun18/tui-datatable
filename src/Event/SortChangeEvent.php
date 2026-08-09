<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Event;

use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * The sort changed.
 *
 * Both properties are null when sorting was cleared and the table went back to
 * the order the rows were given in.
 */
final class SortChangeEvent extends AbstractEvent
{
    public function __construct(
        TableWidget $target,
        public readonly ?string $key,
        public readonly ?SortDirection $direction,
    ) {
        parent::__construct($target);
    }
}
