<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

enum RelationCollectionState
{
	case UNLOADED;
	case PARTIALLY_LOADED;
	case FULLY_LOADED;
}
