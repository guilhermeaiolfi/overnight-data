<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class TrackedRepresentationMap
{
	/** @var array<int, TrackedRepresentation> */
	private array $tracked = [];

	public function has(object $representation): bool
	{
		return array_key_exists(spl_object_id($representation), $this->tracked);
	}

	public function get(object $representation): ?TrackedRepresentation
	{
		return $this->tracked[spl_object_id($representation)] ?? null;
	}

	public function add(TrackedRepresentation $tracked): void
	{
		$id = spl_object_id($tracked->getRepresentation());
		if (isset($this->tracked[$id])) {
			if ($this->tracked[$id] === $tracked) {
				return;
			}

			throw new StateException('Tracked representation map already contains a different state for this object.');
		}

		$this->tracked[$id] = $tracked;
	}

	public function remove(object $representation): void
	{
		unset($this->tracked[spl_object_id($representation)]);
	}

	public function clear(): void
	{
		$this->tracked = [];
	}

	/**
	 * @return list<TrackedRepresentation>
	 */
	public function getAll(): array
	{
		return array_values($this->tracked);
	}
}
