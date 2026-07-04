<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\State\RecordState;

interface RelationChangeInterface
{
	public function getOwner(): RecordState;

	public function getRelationName(): string;

	public function hasChanges(): bool;

	public function clearChanges(): void;
}
