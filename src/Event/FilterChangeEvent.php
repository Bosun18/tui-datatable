<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Event;

use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * The filter changed; `$matchCount` is how many rows survived it.
 */
final class FilterChangeEvent extends AbstractEvent
{
    public function __construct(
        TableWidget $target,
        public readonly int $matchCount,
    ) {
        parent::__construct($target);
    }
}
