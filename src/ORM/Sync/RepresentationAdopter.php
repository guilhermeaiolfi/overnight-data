<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class RepresentationAdopter
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
	) {
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): RepresentationState {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$appliedBinding = $binding->applyToRecordState($record);
		$state = new RepresentationState(
			$appliedBinding,
			$this->buildBaselineRevisions($appliedBinding, $record)
		);

		$this->records->add($record);
		$this->representations->add($representation, $state);

		return $state;
	}

	/**
	 * @param array<string, RecordState> $recordsByCollection
	 */
	public function adoptWithRecords(
		object $representation,
		RepresentationBinding $binding,
		array $recordsByCollection,
		CollectionInterface $rootCollection,
	): RepresentationState {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$rootRecord = $recordsByCollection[$rootCollection->getName()] ?? null;

		if (! $rootRecord instanceof RecordState) {
			throw new SyncException(sprintf(
				'Cannot adopt representation because root collection "%s" was not resolved.',
				$rootCollection->getName()
			));
		}

		$appliedBinding = $this->applyBindingToRecords($binding, $recordsByCollection, $rootCollection);
		$state = new RepresentationState(
			$appliedBinding,
			$this->buildBaselineRevisionsForRecords($appliedBinding, $recordsByCollection)
		);

		foreach ($recordsByCollection as $record) {
			$this->records->add($record);
		}

		$this->representations->add($representation, $state);

		return $state;
	}

	/**
	 * @param array<string, RecordState> $recordsByCollection
	 */
	private function applyBindingToRecords(
		RepresentationBinding $binding,
		array $recordsByCollection,
		CollectionInterface $rootCollection,
	): RepresentationBinding {
		$applied = new RepresentationBinding();

		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			$record = $recordsByCollection[$field->getCollectionName()] ?? null;

			if (! $record instanceof RecordState) {
				throw new SyncException(sprintf(
					'Cannot adopt representation because field path "%s" targets unresolved collection "%s".',
					$fieldBinding->getPath(),
					$field->getCollectionName()
				));
			}

			$applied->addField($fieldBinding->withField(RecordFieldRef::forState($record, $field->getFieldName())));
		}

		foreach ($binding->getExpressions() as $expressionBinding) {
			$applied->addExpression($expressionBinding);
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			$record = $recordsByCollection[$relation->getCollectionName()] ?? $recordsByCollection[$rootCollection->getName()] ?? null;

			if (! $record instanceof RecordState) {
				throw new SyncException(sprintf(
					'Cannot adopt representation because relation path "%s" targets unresolved collection "%s".',
					$relationBinding->getPath(),
					$relation->getCollectionName()
				));
			}

			$applied->addRelation($relationBinding->withRelation(RecordRelationRef::forState($record, $relation->getRelationName())));
		}

		return $applied;
	}

	/**
	 * @param array<string, RecordState> $recordsByCollection
	 * @return array<string, int>
	 */
	private function buildBaselineRevisionsForRecords(
		RepresentationBinding $binding,
		array $recordsByCollection,
	): array {
		$baselineRevisions = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$recordHash = $fieldBinding->getField()->getRecordHash();
			if (! array_key_exists($recordHash, $baselineRevisions)) {
				$baselineRevisions[$recordHash] = $fieldBinding->getField()->getState()->getRevision();
			}
		}

		foreach ($recordsByCollection as $record) {
			$baselineRevisions[$record->getStateHash()] ??= $record->getRevision();
		}

		return $baselineRevisions;
	}

	/**
	 * @return array<string, int>
	 */
	private function buildBaselineRevisions(RepresentationBinding $binding, RecordState $record): array
	{
		$baselineRevisions = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$recordHash = $fieldBinding->getField()->getRecordHash();
			if (! array_key_exists($recordHash, $baselineRevisions)) {
				$baselineRevisions[$recordHash] = $record->getRevision();
			}
		}

		return $baselineRevisions;
	}
}
