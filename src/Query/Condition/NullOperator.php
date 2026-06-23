<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

enum NullOperator: string
{
	case IS_NULL = 'is_null';
	case IS_NOT_NULL = 'is_not_null';
}
