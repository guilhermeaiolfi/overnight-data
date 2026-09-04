<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Key;
use ON\Data\ORM\Representation\Sync\RepresentationSourceIdentities;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Adoption identity map for a prepared writable query (one per {@see QueryRepresentationPlan}).
 *
 * {@see getIdentity()} reads primary-key values from the assembled row using each
 * source's prepared PK place paths (including source-qualified Implicit paths such as
 * `company.id`). Call it with a raw assembled row when resolving Keys during
 * adopt — the map itself is not per row.
 *
 * This is NOT the session RecordState identity map: it does not hold RecordState
 * objects. Keying by source path (rather than collection name) lets root and
 * related sources share a terminal collection while remaining distinct records.
 */
final class QuerySourceIdentities implements RepresentationSourceIdentities
{
	/** @var array<string, RepresentationSource> */
	private array $sourcesByPathKey = [];

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function __construct(array $sources)
	{
		foreach ($sources as $source) {
			$this->sourcesByPathKey[$source->getPathKey()] = $source;
		}
	}

	/**
	 * @param list<string> $sourcePath
	 * @param array<string, mixed>|null $sourceRow
	 */
	public function getIdentity(array $sourcePath, ?array $sourceRow = null): ?Key
	{
		if ($sourceRow === null) {
			return null;
		}

		$pathKey = RepresentationFieldSchema::sourcePathKey($sourcePath);
		$source = $this->sourcesByPathKey[$pathKey] ?? null;
		if (! $source instanceof RepresentationSource) {
			return null;
		}

		$collection = $source->getCollection();
		$values = [];
		$fieldNames = $collection->getPrimaryKey();
		$placePaths = $source->getPrimaryKeyPlacePaths();

		foreach ($fieldNames as $index => $fieldName) {
			$path = $placePaths[$index] ?? null;
			if ($path === null || ! array_key_exists($path, $sourceRow)) {
				return null;
			}

			$value = $sourceRow[$path];
			if ($value === null) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return $collection->getKey($values);
	}
}
