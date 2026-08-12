<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

/**
 * One place key's fetch home: the load-local parser key, and optionally a
 * loaded to-one child destination to read from instead of this branch's row.
 */
final class PlaceBinding
{
	/**
	 * @param list<string>|null $childPath relation names from this branch to the child destination
	 */
	public function __construct(
		private readonly string $loadKey,
		private readonly ?array $childPath = null,
	) {
	}

	public function getLoadKey(): string
	{
		return $this->loadKey;
	}

	/**
	 * @return list<string>|null
	 */
	public function getChildPath(): ?array
	{
		return $this->childPath;
	}

	public function isChildDestination(): bool
	{
		return $this->childPath !== null;
	}
}
