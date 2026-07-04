<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelatedCollection;

interface RelationPersistencePlannerInterface
{
	public function plan(PersistenceContext $context, RelationInterface $relation, RelatedCollection $collection): void;
}
