<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable;

/**
 * Direction of a column sort.
 */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
