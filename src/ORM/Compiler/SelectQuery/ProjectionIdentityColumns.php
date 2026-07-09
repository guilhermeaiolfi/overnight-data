<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Maps projected source-path primary-key fields to result row keys. It is used
 * during projection adoption to read hidden or public identity values from the
 * source row before resolving records through RecordStateStore.
 *
 * This is NOT the session RecordState identity map: it does not hold RecordState
 * objects. It is compiled query/result-row metadata describing where identity
 * columns can be found, keyed by source path + primary-key field. SelectQuery may
 * inject INTERNAL-tagged identity selections that must not appear in public
 * results but are required to resolve RecordState keys during
 * QueryRepresentationStateBuilder::build(). Keying by source path (rather than
 * collection name) lets root and related sources share a terminal collection
 * while remaining distinct records.
 */
use ON\Data\ORM\State\RepresentationFieldSchema;

final class ProjectionIdentityColumns
{
	/**
	 * @var array<string, array<string, string>>
	 */
	private array $entries = [];

	/**
	 * @param list<string> $sourcePath
	 */
	public function add(array $sourcePath, string $fieldName, string $resultKey): void
	{
		$this->entries[$this->sourcePathKey($sourcePath)][$fieldName] = $resultKey;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function get(array $sourcePath, string $fieldName): ?string
	{
		return $this->entries[$this->sourcePathKey($sourcePath)][$fieldName] ?? null;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function sourcePathKey(array $sourcePath): string
	{
		return RepresentationFieldSchema::sourcePathKey($sourcePath);
	}
}
