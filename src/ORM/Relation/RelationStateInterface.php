<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Record\RecordState;

interface RelationStateInterface
{
	public function getOwner(): RecordState;

	public function getRelationName(): string;

	public function hasChanges(): bool;

	public function clearChanges(): void;
}
