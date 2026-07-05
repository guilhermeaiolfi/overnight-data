<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class SessionContext
{
	private RecordStateStore $records;
	private RepresentationStore $representations;

	/** @var RelationStateStore<ToManyRelationState> */
	private RelationStateStore $relations;

	/** @var RelationStateStore<ToOneRelationState> */
	private RelationStateStore $references;

	/**
	 * @param RelationStateStore<ToManyRelationState>|null $relations
	 * @param RelationStateStore<ToOneRelationState>|null $references
	 */
	public function __construct(
		?RecordStateStore $records = null,
		?RepresentationStore $representations = null,
		?RelationStateStore $relations = null,
		?RelationStateStore $references = null,
	) {
		$this->records = $records ?? new RecordStateStore();
		$this->representations = $representations ?? new RepresentationStore();
		$this->relations = $relations ?? new RelationStateStore();
		$this->references = $references ?? new RelationStateStore();
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStore
	{
		return $this->representations;
	}

	/**
	 * @return RelationStateStore<ToManyRelationState>
	 */
	public function getRelations(): RelationStateStore
	{
		return $this->relations;
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getReferences(): RelationStateStore
	{
		return $this->references;
	}

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->relations->clear();
		$this->references->clear();
	}
}
