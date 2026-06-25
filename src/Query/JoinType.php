<?php

declare(strict_types=1);

namespace ON\Data\Query;

enum JoinType
{
	case INNER;
	case LEFT;
}
