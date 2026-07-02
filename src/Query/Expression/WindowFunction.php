<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

enum WindowFunction: string
{
	case ROW_NUMBER = 'ROW_NUMBER';
	case RANK = 'RANK';
	case DENSE_RANK = 'DENSE_RANK';
}
