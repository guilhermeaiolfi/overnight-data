<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\Binding\ProjectionSourceResolver;
use ON\Data\ORM\Binding\ProjectionSourceTarget;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;

final class ManualProjectionSourceResolver implements ProjectionSourceResolver
{
	/** @var array<int, RecordState> */
	private array $sourceRecords = [];

	public function resolve(object $source): ProjectionSourceTarget
	{
		if ($source instanceof ManualProjectionPropertySource) {
			$record = $source->getTargetRecord();

			return new ProjectionSourceTarget($record->getCollection(), new RepresentationBinding(), $record);
		}

		if ($source instanceof ManualProjectionRelationRef) {
			if ($source->getDefinition()->getCardinality() === 'many') {
				throw new StateException(sprintf(
					"Cannot select MANY relation source '%s' without first creating or identifying one concrete relation item.",
					implode('.', $source->getPath())
				));
			}

			return new ProjectionSourceTarget($source->getDefinition()->getCollection(), new RepresentationBinding());
		}

		$record = $this->recordFor($source);
		if ($record instanceof RecordState) {
			return new ProjectionSourceTarget($record->getCollection(), new RepresentationBinding(), $record);
		}

		throw new StateException(sprintf(
			"Cannot resolve manual projection source '%s' because it has no concrete record identity.",
			$this->describeSource($source),
		));
	}

	public function rememberSource(object $source, RecordState $record): void
	{
		$this->sourceRecords[spl_object_id($source)] = $record;
	}

	public function recordFor(object $source): ?RecordState
	{
		return $this->sourceRecords[spl_object_id($source)] ?? null;
	}

	public function clear(): void
	{
		$this->sourceRecords = [];
	}

	private function describeSource(object $source): string
	{
		if ($source instanceof ManualProjectionRelationRef) {
			return implode('.', $source->getPath());
		}

		return $source::class;
	}
}
