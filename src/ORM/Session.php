<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionStore;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceStore;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\GraphAdopter;
use ON\Data\ORM\Sync\RepresentationAdopter;
use ON\Data\ORM\Sync\RepresentationSyncer;
use ON\Data\ORM\Sync\SyncResult;

final class Session
{
	private RecordStateStore $records;
	private RepresentationStore $representations;
	private RelatedCollectionStore $relations;
	private RelatedReferenceStore $references;
	private RepresentationAdopter $adopter;
	private GraphAdopter $graphAdopter;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
	) {
		$this->records = new RecordStateStore();
		$this->representations = new RepresentationStore();
		$this->relations = new RelatedCollectionStore();
		$this->references = new RelatedReferenceStore();
		$this->adopter = new RepresentationAdopter($this->records, $this->representations);
		$this->graphAdopter = new GraphAdopter();
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStore
	{
		return $this->representations;
	}

	public function getRelations(): RelatedCollectionStore
	{
		return $this->relations;
	}

	public function getReferences(): RelatedReferenceStore
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
	): RepresentationState {
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

	public function trackReference(RelatedReference $reference): RelatedReference
	{
		$this->references->add($reference);

		return $reference;
	}

	public function sync(?object $representation = null, ?RepresentationBinding $binding = null): SyncResult
	{
		if ($representation !== null) {
			if (! $this->representations->has($representation) && ! $binding instanceof RepresentationBinding) {
				throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationBinding.');
			}

			$this->graphAdopter->adopt(
				$representation,
				$this->representations,
				$this->records,
				$this->relations,
				$this->references,
				$binding
			);

			return $this->syncer->sync($this->representations, $this->records, $this->relations, $this->references);
		}

		return $this->syncer->sync($this->representations, $this->records, $this->relations, $this->references, $representation);
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->representations, $this->records, $this->relations, $this->references);
	}
}
