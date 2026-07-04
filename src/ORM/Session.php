<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationAdopter;

final class Session
{
	private RecordStateMap $records;
	private TrackedRepresentationMap $representations;
	private RelatedCollectionMap $relations;
	private RepresentationAdopter $adopter;
	private FlushExecutor $flusher;

	public function __construct(CommandExecutorInterface $executor, ?FlushExecutor $flusher = null)
	{
		$this->records = new RecordStateMap();
		$this->representations = new TrackedRepresentationMap();
		$this->relations = new RelatedCollectionMap();
		$this->adopter = new RepresentationAdopter($this->records, $this->representations);
		$this->flusher = $flusher ?? new FlushExecutor($executor);
	}

	public function getRecords(): RecordStateMap
	{
		return $this->records;
	}

	public function getRepresentations(): TrackedRepresentationMap
	{
		return $this->representations;
	}

	public function getRelations(): RelatedCollectionMap
	{
		return $this->relations;
	}

	public function trackRecord(RecordState $record): RecordState
	{
		$this->records->add($record);

		return $record;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function trackNew(CollectionInterface $collection, array $values = []): RecordState
	{
		return $this->trackRecord(RecordState::new($collection, $values));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function trackClean(Key $key, array $values): RecordState
	{
		return $this->trackRecord(RecordState::clean($key, $values));
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): TrackedRepresentation {
		return $this->adopter->adopt($representation, $binding, $record);
	}

	public function removeRecord(RecordState $record): void
	{
		$this->records->add($record);
		$record->markRemoved();
	}

	public function trackRelation(RelatedCollection $collection): RelatedCollection
	{
		$this->relations->add($collection);

		return $collection;
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->representations, $this->records, $this->relations);
	}
}
