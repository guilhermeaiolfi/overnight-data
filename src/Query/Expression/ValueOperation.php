<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

enum ValueOperation: string
{
	case UPPER = 'upper';
	case LOWER = 'lower';
	case CONCAT = 'concat';
	case COALESCE = 'coalesce';
	case ADD = 'add';
}
