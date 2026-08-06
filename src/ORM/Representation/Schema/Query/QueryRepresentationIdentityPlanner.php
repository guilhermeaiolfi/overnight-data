<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

/**
 * Plans hidden identity selections for flat mutable projection adoption.
 *
 * Given compiled structural RepresentationSource entries and a SelectQuery, this
 * ensures the query result carries enough primary-key data to adopt every
 * projection source represented by flat projected fields. It may mutate the query by
 * adding INTERNAL-tagged selections and returns a QuerySourceIdentities map keyed
 * by source path + primary-key field.
 *
 * Exists to separate identity planning from structural schema compilation: it
 * never creates field schemas, relation schemas, or normalizes selections.
 */
final class QueryRepresentationIdentityPlanner
{
	private int $internalResultKeyCounter = 0;

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function plan(SelectQuery $query, array $sources): QuerySourceIdentities
	{
		$this->internalResultKeyCounter = 0;

		$identities = new QuerySourceIdentities($sources);

		foreach ($sources as $source) {
			$this->ensureIdentitySelections($query, $source, $identities);
		}

		return $identities;
	}

	/**
	 * Plan INTERNAL identity selections onto a nested relation level's selection list.
	 *
	 * @param list<RepresentationSource> $sources sources relative to that level's schema
	 */
	public function planLevel(RelationRef $level, array $sources): QuerySourceIdentities
	{
		$identities = new QuerySourceIdentities($sources);

		foreach ($sources as $source) {
			$this->ensureLevelIdentitySelections($level, $source, $identities);
		}

		return $identities;
	}

	private function ensureIdentitySelections(
		SelectQuery $query,
		RepresentationSource $source,
		QuerySourceIdentities $identities,
	): void {
		$sourcePath = $source->getPath();
		$collection = $source->getCollection();

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($source->hasField($fieldName)) {
				continue;
			}

			if ($identities->getResultKey($sourcePath, $fieldName) !== null) {
				continue;
			}

			$resultKey = $this->generateInternalResultKey(
				static fn (string $key): bool => $query->getSelections()->hasSelectionKey($key),
			);
			$fieldRef = $this->resolveFieldRef($query, $sourcePath, $fieldName, $collection);
			$query->getSelections()->add(
				$fieldRef->as($resultKey),
				SelectionTag::INTERNAL,
			);
			$identities->add($sourcePath, $fieldName, $resultKey);
		}
	}

	private function ensureLevelIdentitySelections(
		RelationRef $level,
		RepresentationSource $source,
		QuerySourceIdentities $identities,
	): void {
		$sourcePath = $source->getPath();
		$collection = $source->getCollection();

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($source->hasField($fieldName)) {
				continue;
			}

			if ($identities->getResultKey($sourcePath, $fieldName) !== null) {
				continue;
			}

			$resultKey = $this->generateInternalResultKey(
				static fn (string $key): bool => $level->getSelections()->hasSelectionKey($key),
			);
			$fieldRef = $this->resolveLevelFieldRef($level, $sourcePath, $fieldName, $collection);
			$level->getSelections()->add(
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

	/**
	 * @param list<string> $sourcePath
	 */
	private function resolveLevelFieldRef(
		RelationRef $level,
		array $sourcePath,
		string $fieldName,
		CollectionInterface $collection,
	): FieldRef {
		if ($sourcePath === []) {
			return $level->field($fieldName);
		}

		$relationRef = $level;

		foreach ($sourcePath as $segment) {
			$relationRef = $relationRef->relation($segment);
		}

		if ($relationRef->getCollection()->getName() !== $collection->getName()) {
			throw new StateException(sprintf(
				"Cannot plan projection identity for collection '%s' because source path '%s' resolved to '%s'.",
				$collection->getName(),
				implode('.', $sourcePath),
				$relationRef->getCollection()->getName(),
			));
		}

		return $relationRef->field($fieldName);
	}

	/**
	 * @param callable(string): bool $keyExists
	 */
	private function generateInternalResultKey(callable $keyExists): string
	{
		do {
			$key = '_od_internal_' . ++$this->internalResultKeyCounter;
		} while ($keyExists($key));

		return $key;
	}
}
