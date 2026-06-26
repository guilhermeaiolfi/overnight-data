<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

enum LoadStrategy
{
	case JOIN;
	case SEPARATE_QUERY;
}
