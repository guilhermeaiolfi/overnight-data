<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\ExistingIntentStore;

final class SessionContext
{
	private RecordStateStore $records;
	private RepresentationStateStore $representations;
	private ExistingIntentStore $existingIntents;

	/** @var RelationStateStore<ToManyRelationState> */
	private RelationStateStore $toManyRelations;

	/** @var RelationStateStore<ToOneRelationState> */
	private RelationStateStore $toOneRelations;

	/**
	 * @param RelationStateStore<ToManyRelationState>|null $toManyRelations
	 * @param RelationStateStore<ToOneRelationState>|null $toOneRelations
	 */
	public function __construct(
		?RecordStateStore $records = null,
		?RepresentationStateStore $representations = null,
		?RelationStateStore $toManyRelations = null,
		?RelationStateStore $toOneRelations = null,
	) {
		$this->records = $records ?? new RecordStateStore();
		$this->representations = $representations ?? new RepresentationStateStore();
		$this->existingIntents = new ExistingIntentStore();
		$this->toManyRelations = $toManyRelations ?? new RelationStateStore();
		$this->toOneRelations = $toOneRelations ?? new RelationStateStore();
	}

	public function getExistingIntents(): ExistingIntentStore
	{
		return $this->existingIntents;
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStateStore
	{
		return $this->representations;
	}

	/**
	 * @return RelationStateStore<ToManyRelationState>
	 */
	public function getToManyRelations(): RelationStateStore
	{
		return $this->toManyRelations;
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getToOneRelations(): RelationStateStore
	{
		return $this->toOneRelations;
	}

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->existingIntents->clear();
		$this->toManyRelations->clear();
		$this->toOneRelations->clear();
	}
}
