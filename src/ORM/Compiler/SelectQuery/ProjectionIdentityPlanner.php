<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Plans hidden identity selections for flat mutable projection adoption.
 *
 * Given a compiled structural RepresentationBinding and its SelectQuery, this
 * ensures the query result carries enough primary-key data to adopt every
 * source path represented by flat projected fields. It may mutate the query by
 * adding INTERNAL-tagged selections and returns a ProjectionIdentityMap keyed by
 * source path + primary-key field.
 *
 * Exists to separate identity planning from structural binding compilation: it
 * never creates field bindings, relation bindings, or normalizes selections.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

final class ProjectionIdentityPlanner
{
	private int $internalResultKeyCounter = 0;

	public function plan(
		SelectQuery $query,
		RepresentationBinding $binding,
	): ProjectionIdentityMap {
		$this->internalResultKeyCounter = 0;

		$identities = new ProjectionIdentityMap();

		foreach ($this->collectProjectedSourceFields($binding) as $sourceField) {
			$this->ensureIdentitySelections($query, $binding, $sourceField, $identities);
		}

		return $identities;
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	private function collectProjectedSourceFields(RepresentationBinding $binding): array
	{
		/** @var array<string, RepresentationFieldBinding> $fieldsBySourcePath */
		$fieldsBySourcePath = [];

		foreach ($binding->getFields() as $field) {
			$sourceKey = $field->getSourcePathKey();

			if (! array_key_exists($sourceKey, $fieldsBySourcePath)) {
				$fieldsBySourcePath[$sourceKey] = $field;
			}
		}

		return array_values($fieldsBySourcePath);
	}

	private function ensureIdentitySelections(
		SelectQuery $query,
		RepresentationBinding $binding,
		RepresentationFieldBinding $sourceField,
		ProjectionIdentityMap $identities,
	): void {
		$sourcePath = $sourceField->getSourcePath();
		$collection = $sourceField->getCollection();

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($binding->hasFieldForSource($sourcePath, $fieldName)) {
				continue;
			}

			if ($identities->get($sourcePath, $fieldName) !== null) {
				continue;
			}

			$resultKey = $this->generateInternalResultKey($query);
			$fieldRef = $this->resolveFieldRef($query, $sourcePath, $fieldName, $collection);
			$query->getSelections()->add(
				$fieldRef->as($resultKey),
				SelectionTag::INTERNAL,
			);
			$identities->add($sourcePath, $fieldName, $resultKey);
		}
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function resolveFieldRef(
		SelectQuery $query,
		array $sourcePath,
		string $fieldName,
		CollectionInterface $collection,
	): FieldRef {
		if ($sourcePath === []) {
			$fieldRef = $query->field($fieldName);

			if (! $fieldRef instanceof FieldRef) {
				throw new StateException(sprintf(
					"Cannot plan projection identity for root primary key field '%s' because it does not resolve to a query field.",
					$fieldName,
				));
			}

			return $fieldRef;
		}

		$relationRef = null;

		foreach ($sourcePath as $segment) {
			$relationRef = $relationRef === null
				? $query->relation($segment)
				: $relationRef->relation($segment);
		}

		if (! $relationRef instanceof RelationRef) {
			throw new StateException(sprintf(
				"Cannot plan projection identity for collection '%s' because source path '%s' could not be resolved.",
				$collection->getName(),
				implode('.', $sourcePath),
			));
		}

		return $relationRef->field($fieldName);
	}

	private function generateInternalResultKey(SelectQuery $query): string
	{
		do {
			$key = '_od_internal_' . ++$this->internalResultKeyCounter;
		} while ($query->getSelections()->hasSelectionKey($key));

		return $key;
	}
}
