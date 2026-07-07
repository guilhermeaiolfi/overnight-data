<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Maps hidden internal result keys to primary-key fields for flat mutable
 * projection adoption, keyed by the source path that reaches the owning record.
 *
 * Exists because SelectQuery may inject INTERNAL-tagged identity selections that
 * must not appear in public results but are required to resolve RecordState keys
 * during ProjectionRepresentationAdopter::adopt(). Keying by source path (rather
 * than collection name) lets root and related sources share a terminal
 * collection while remaining distinct records.
 */
final class ProjectionIdentityMap
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

	public function isEmpty(): bool
	{
		return $this->entries === [];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function all(): array
	{
		return $this->entries;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function sourcePathKey(array $sourcePath): string
	{
		return implode('.', $sourcePath);
	}
}
