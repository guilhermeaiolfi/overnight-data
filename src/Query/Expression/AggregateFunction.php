<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

enum AggregateFunction: string
{
	case COUNT = 'count';
	case COUNT_DISTINCT = 'count_distinct';
	case SUM = 'sum';
}
