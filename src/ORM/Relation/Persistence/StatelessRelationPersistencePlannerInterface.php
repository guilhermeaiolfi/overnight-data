<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

/** The same planner instance can process independent changes across multiple flushes. */
interface StatelessRelationPersistencePlannerInterface extends RelationPersistencePlannerInterface
{
}
