<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

/**
 * Standard key-pair collector matching child own-record fields against parent
 * own-record fields (used by BelongsTo, HasOne, HasMany, FirstOfMany, and
 * custom foreign-key relations).
 */
final class KeyPairCollector implements RowCollectorInterface
{
	/**
	 * @param list<string> $parentFields
	 * @param list<string> $childFields
	 */
	public function __construct(
		private readonly array $parentFields,
		private readonly array $childFields,
	) {
	}

	public function collect(
		RelationLoadBranch $branch,
		array $row,
		?ResultRecord $joinedParent,
	): ?ResultRecord {
		$record = $branch->ingestRecord($branch->extractRow($row));

		if ($record === null) {
			return null;
		}

		$childTuple = KeyEncoder::extractTuple($this->childFields, $record->values);

		if ($childTuple === null) {
			return $record;
		}

		$parent = $branch->getParent();

		if ($joinedParent !== null) {
			$parentTuple = KeyEncoder::extractTuple($this->parentFields, $joinedParent->values);

			if (
				$parentTuple !== null
				&& KeyEncoder::encodeTuple($parentTuple) === KeyEncoder::encodeTuple($childTuple)
			) {
				$parent->attachChild($joinedParent, $branch, $record);
			}

			return $record;
		}

		foreach ($parent->findRecordsByKey($this->parentFields, $childTuple) as $parentRecord) {
			$parent->attachChild($parentRecord, $branch, $record);
		}

		return $record;
	}
}
