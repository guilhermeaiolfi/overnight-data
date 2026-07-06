<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\Binding\ProjectionIdentityProviderInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionItem;

final class ManualProjectionIdentityProvider implements ProjectionIdentityProviderInterface
{
	/** @var array<int, RecordState> */
	private array $sourceRecords = [];

	public function fieldForSelection(SelectionItem $selection, FieldRef $fieldRef): ?RecordFieldRef
	{
		return RecordFieldRef::forState(
			$this->recordForSource($fieldRef->getSource(), $fieldRef),
			$fieldRef->getName()
		);
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

	private function recordForSource(QuerySourceInterface $source, FieldRef $fieldRef): RecordState
	{
		$record = $this->recordFor($source);
		if ($record instanceof RecordState) {
			return $record;
		}

		if ($source instanceof RelationRef && $source->getDefinition()->getCardinality() === 'many') {
			throw new StateException(sprintf(
				"Cannot select MANY relation field '%s' without first creating or identifying one concrete relation item.",
				implode('.', $fieldRef->getPath())
			));
		}

		throw new StateException(sprintf(
			"Cannot select field '%s' because its projection source has no concrete record identity.",
			implode('.', $fieldRef->getPath())
		));
	}
}
