<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

use ON\Data\ORM\Relation\ToManyRelationState;

/**
 * How completely a query (or other producer) loaded a to-many relation for sync.
 *
 * Unknown — no load metadata (inbound graph / identify / hand-built schemas).
 * Partial — query loaded a filtered or sliced subset (relation where / limit / offset).
 * Full — query loaded the association as defined (no relation where / limit / offset).
 *
 * Only Full enables collection-replace removals in {@see ToManyRelationState::syncFromItems()}.
 */
enum RelationLoadKnowledge
{
	case Unknown;
	case Partial;
	case Full;
}
