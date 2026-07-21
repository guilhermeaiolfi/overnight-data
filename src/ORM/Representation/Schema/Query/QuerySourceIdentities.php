<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\ORM\Representation\Sync\RepresentationSourceIdentities;

/**
 * Adoption identity map for a prepared writable query (one per {@see QueryRepresentationPlan}).
 *
 * Owns the locator map (source path + primary-key field -> result row key) used
 * to read hidden or public identity values from the source row before resolving
 * records through RecordStateStore. Locators are fixed for the fetch and start
 * empty; callers fill them via {@see add()} while planning. Call {@see getIdentity()}
 * with a raw row when resolving Keys during adopt — the map itself is not per row.
 *
 * This is NOT the session RecordState identity map: it does not hold RecordState
 * objects. It is compiled query/result-row metadata describing where identity
 * columns can be found, keyed by source path + primary-key field. SelectQuery may
 * inject INTERNAL-tagged identity selections that must not appear in public
 * results but are required to resolve RecordState keys during flat hydrate
 * adoption. Keying by source path (rather than collection name) lets root and
 * related sources share a terminal collection while remaining distinct records.
 */
final class QuerySourceIdentities implements RepresentationSourceIdentities
{
	/** @var array<string, RepresentationSource> */
	private array $sourcesByPathKey = [];

	/** @var array<string, array<string, string>> */
	private array $locators = [];

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
	 */
	public function add(array $sourcePath, string $fieldName, string $resultKey): void
	{
		$this->locators[$this->sourcePathKey($sourcePath)][$fieldName] = $resultKey;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function getResultKey(array $sourcePath, string $fieldName): ?string
	{
		return $this->locators[$this->sourcePathKey($sourcePath)][$fieldName] ?? null;
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

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			$value = null;
			if ($path !== null && array_key_exists($path, $sourceRow)) {
				$value = $sourceRow[$path];
			}

			if ($value === null) {
				$resultKey = $this->getResultKey($source->getPath(), $fieldName);

				if ($resultKey === null) {
					return null;
				}

				if (! array_key_exists($resultKey, $sourceRow)) {
					throw new StateException(sprintf(
						"Cannot build projection representation for collection '%s' because internal result key '%s' for primary key field '%s' is missing from the source row.",
						$collection->getName(),
						$resultKey,
						$fieldName,
					));
				}

				$value = $sourceRow[$resultKey];
			}

			if ($value === null) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return $collection->getKey($values);
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function sourcePathKey(array $sourcePath): string
	{
		return RepresentationFieldSchema::sourcePathKey($sourcePath);
	}
}
