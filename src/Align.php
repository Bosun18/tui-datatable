<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable;

/**
 * Horizontal alignment of a cell's content within its column.
 */
enum Align: string
{
    case Left = 'left';
    case Right = 'right';
    case Center = 'center';
}
