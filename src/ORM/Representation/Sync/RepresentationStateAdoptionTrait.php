<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
trait RepresentationStateAdoptionTrait
{
	private RepresentationReader $representationReader;

	public function adopt(
		object $representation,
		RepresentationState $state,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		$representations = $this->getRepresentations();
		$records = $this->getRecords();

		if ($mode === RepresentationAttachmentMode::Add && $representations->has($representation)) {
			throw new SyncException('Cannot attach representation because it is already tracked.');
		}

		foreach ($this->uniqueRecordsFromState($state) as $record) {
			$records->add($record);
		}

		if ($mode === RepresentationAttachmentMode::Replace && $representations->has($representation)) {
			$representations->remove($representation);
		}

		$representations->add($representation, $state);

		return $state;
	}

	public function adoptRecord(
		object $representation,
		RepresentationSchema $schema,
		RecordState $record,
	): RepresentationState {
		return $this->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => $record,
			]),
		);
	}

	/**
	 * @return list<RepresentationState>
	 */
	private function adoptGraph(
		object $root,
		?RepresentationSchema $rootSchema = null,
	): array {
		$representations = $this->getRepresentations();
		$records = $this->getRecords();

		if ($representations->get($root) === null) {
			if (! $rootSchema instanceof RepresentationSchema) {
				throw new StateException('Cannot adopt representation graph because the root representation is not tracked and no root schema was provided.');
			}

			$this->adoptRecord(
				$root,
				$rootSchema,
				$this->recordResolver->resolve($root, $rootSchema, $records, true)
			);
		}

		$visited = [];
		$adopted = [];

		$this->walkGraph($root, $visited, $adopted);

		return $adopted;
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function walkGraph(
		object $representation,
		array &$visited,
		array &$adopted,
	): void {
		$representations = $this->getRepresentations();

		$id = spl_object_id($representation);
		if (array_key_exists($id, $visited)) {
			return;
		}

		$tracked = $representations->get($representation);
		if ($tracked === null) {
			throw new StateException('Cannot walk representation graph because a representation is not tracked.');
		}

		$visited[$id] = true;
		foreach ($tracked->getSchema()->getRelations() as $relationSchema) {
			if ($relationSchema->isMany()) {
				foreach ($this->representationReader->readItems($representation, $relationSchema, $this->graphAdoptionError(...)) as $item) {
					$this->adoptRelatedAndWalkGraph($item, $relationSchema->getRelatedSchema(), $visited, $adopted);
				}

				continue;
			}

			if ($relationSchema->isSingle()) {
				$target = $this->representationReader->readTarget($representation, $relationSchema, $this->graphAdoptionError(...));
				if ($target !== null) {
					$this->adoptRelatedAndWalkGraph($target, $relationSchema->getRelatedSchema(), $visited, $adopted);
				}
			}
		}
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function adoptRelatedAndWalkGraph(
		object $representation,
		RepresentationSchema $schema,
		array &$visited,
		array &$adopted,
	): void {
		$representations = $this->getRepresentations();
		$records = $this->getRecords();

		if (! $representations->has($representation)) {
			$adopted[] = $this->adoptRecord(
				$representation,
				$schema,
				$this->recordResolver->resolve($representation, $schema, $records, false)
			);
		}

		$this->walkGraph($representation, $visited, $adopted);
	}

	/**
	 * @return list<RecordState>
	 */
	private function uniqueRecordsFromState(RepresentationState $state): array
	{
		$records = [];
		foreach ($state->getFieldItems() as $item) {
			$record = $item->getRecord();
			$records[spl_object_id($record)] = $record;
		}

		foreach ($state->getRelationItems() as $item) {
			$record = $item->getOwnerRecord();
			$records[spl_object_id($record)] = $record;
		}

		return array_values($records);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function graphAdoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}
}
