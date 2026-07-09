<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

enum RepresentationRelationCardinality
{
	case ONE;
	case MANY;
}
