<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

enum ComparisonOperator: string
{
	case EQ = 'eq';
	case NEQ = 'neq';
	case GT = 'gt';
	case GTE = 'gte';
	case LT = 'lt';
	case LTE = 'lte';
	case LIKE = 'like';
	case NOT_LIKE = 'not_like';
}
