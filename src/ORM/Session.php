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
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\GraphAdopter;
use ON\Data\ORM\Sync\GraphAdoptionResult;
use ON\Data\ORM\Sync\RepresentationAdopter;
use ON\Data\ORM\Sync\RepresentationSyncer;
use ON\Data\ORM\Sync\SyncResult;

final class Session
{
	private RecordStateMap $records;
	private TrackedRepresentationMap $representations;
	private RelatedCollectionMap $relations;
	private RelatedReferenceMap $references;
	private RepresentationAdopter $adopter;
	private GraphAdopter $graphAdopter;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
	)
	{
		$this->records = new RecordStateMap();
		$this->representations = new TrackedRepresentationMap();
		$this->relations = new RelatedCollectionMap();
		$this->references = new RelatedReferenceMap();
		$this->adopter = new RepresentationAdopter($this->records, $this->representations);
		$this->graphAdopter = new GraphAdopter();
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
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

	public function getReferences(): RelatedReferenceMap
	{
		return $this->references;
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

	public function adoptGraph(object $representation): GraphAdoptionResult
	{
		return $this->graphAdopter->adopt(
			$representation,
			$this->representations,
			$this->records,
			$this->relations,
			$this->references
		);
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

	public function trackReference(RelatedReference $reference): RelatedReference
	{
		$this->references->add($reference);

		return $reference;
	}

	public function sync(?object $representation = null): SyncResult
	{
		return $this->syncer->sync($this->representations, $this->records, $this->relations, $this->references, $representation);
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->representations, $this->records, $this->relations, $this->references);
	}
}
