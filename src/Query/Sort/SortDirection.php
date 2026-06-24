<?php

declare(strict_types=1);

namespace ON\Data\Query\Sort;

enum SortDirection: string
{
	case ASC = 'asc';
	case DESC = 'desc';
}
