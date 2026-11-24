<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

/** @internal */
enum QueryUsage
{
	case SCALAR_SUBQUERY;
	case IN_SUBQUERY;
	case EXISTS_SUBQUERY;
}
