<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

enum LogicalOperator: string
{
	case AND = 'and';
	case OR = 'or';
}
