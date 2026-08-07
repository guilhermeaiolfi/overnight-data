<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

/**
 * Plans hidden identity selections for flat mutable projection adoption.
 *
 * Given compiled structural RepresentationSource entries and a projection level
 * ({@see SelectQuery} root or {@see RelationRef} nested level), this ensures the
 * level's result carries enough primary-key data to adopt every projection source
 * represented by flat projected fields. It may mutate that level's selection list
 * by adding INTERNAL-tagged selections and returns a QuerySourceIdentities map
 * keyed by source path + primary-key field.
 *
 * Exists to separate identity planning from structural schema compilation: it
 * never creates field schemas, relation schemas, or normalizes selections.
 */
final class QueryRepresentationIdentityPlanner
{
	private int $internalResultKeyCounter = 0;

	/**
	 * Plan INTERNAL identities onto a root query or nested relation level.
	 *
	 * @param list<RepresentationSource> $sources sources relative to that level's schema
	 */
	public function planIdentities(
		SelectQuery|RelationRef $level,
		array $sources,
		bool $resetCounter = false,
	): QuerySourceIdentities {
		if ($resetCounter) {
			$this->internalResultKeyCounter = 0;
		}

		$identities = new QuerySourceIdentities($sources);
		$selections = $this->selectionsOf($level);

		foreach ($sources as $source) {
			$this->ensureIdentitySelections($level, $selections, $source, $identities);
		}

		return $identities;
	}

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function plan(SelectQuery $query, array $sources): QuerySourceIdentities
	{
		return $this->planIdentities($query, $sources, resetCounter: true);
	}

	/**
	 * @param list<RepresentationSource> $sources sources relative to that level's schema
	 */
	public function planLevel(RelationRef $level, array $sources): QuerySourceIdentities
	{
		return $this->planIdentities($level, $sources);
	}

	private function ensureIdentitySelections(
		SelectQuery|RelationRef $level,
		SelectionList $selections,
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
				static fn (string $key): bool => $selections->hasSelectionKey($key),
			);
			$fieldRef = $this->resolveFieldRef($level, $sourcePath, $fieldName, $collection);
			$selections->add(
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
		SelectQuery|RelationRef $level,
		array $sourcePath,
		string $fieldName,
		CollectionInterface $collection,
	): FieldRef {
		if ($sourcePath === []) {
			return $this->resolveOwnField($level, $fieldName);
		}

		$relationRef = $level instanceof RelationRef ? $level : null;

		foreach ($sourcePath as $segment) {
			$relationRef = $relationRef === null
				? $level->relation($segment)
				: $relationRef->relation($segment);
		}

		if (
			! $relationRef instanceof RelationRef
			|| $relationRef->getCollection()->getName() !== $collection->getName()
		) {
			throw new StateException(sprintf(
				"Cannot plan projection identity for collection '%s' because source path '%s' could not be resolved.",
				$collection->getName(),
				implode('.', $sourcePath),
			));
		}

		return $relationRef->field($fieldName);
	}

	private function resolveOwnField(SelectQuery|RelationRef $level, string $fieldName): FieldRef
	{
		if ($level instanceof RelationRef) {
			return $level->field($fieldName);
		}

		$fieldRef = $level->field($fieldName);

		if (! $fieldRef instanceof FieldRef) {
			throw new StateException(sprintf(
				"Cannot plan projection identity for root primary key field '%s' because it does not resolve to a query field.",
				$fieldName,
			));
		}

		return $fieldRef;
	}

	private function selectionsOf(SelectQuery|RelationRef $level): SelectionList
	{
		return $level->getSelections();
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
