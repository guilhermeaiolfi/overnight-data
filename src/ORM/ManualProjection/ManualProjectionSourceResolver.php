<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\Binding\ProjectionSourceResolver;
use ON\Data\ORM\Binding\ProjectionSourceTarget;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;

final class ManualProjectionSourceResolver implements ProjectionSourceResolver
{
	/** @var array<int, RecordState> */
	private array $sourceRecords = [];

	public function resolve(QuerySourceInterface $source): ProjectionSourceTarget
	{
		$record = $this->recordFor($source);
		if ($record instanceof RecordState) {
			return new ProjectionSourceTarget($record->getCollection(), new RepresentationBinding(), $record);
		}

		if ($source instanceof RelationRef && $source->getDefinition()->getCardinality() === 'many') {
			throw new StateException(sprintf(
				"Cannot select MANY relation source '%s' without first creating or identifying one concrete relation item.",
				implode('.', $source->getPath())
			));
		}

		throw new StateException(sprintf(
			"Cannot select source '%s' because its projection source has no concrete record identity.",
			implode('.', $source->getPath())
		));
	}

	public function rememberSource(QuerySourceInterface $source, RecordState $record): void
	{
		$this->sourceRecords[spl_object_id($source)] = $record;
	}

	public function recordFor(QuerySourceInterface $source): ?RecordState
	{
		return $this->sourceRecords[spl_object_id($source)] ?? null;
	}

	public function clear(): void
	{
		$this->sourceRecords = [];
	}
}
