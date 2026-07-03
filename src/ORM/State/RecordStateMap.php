<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordStateMap
{
	/** @var array<string, RecordState> */
	private array $states = [];

	public function has(Key $key): bool
	{
		return array_key_exists($key->getHash(), $this->states);
	}

	public function get(Key $key): ?RecordState
	{
		return $this->states[$key->getHash()] ?? null;
	}

	public function add(RecordState $state): void
	{
		$key = $state->getKey();
		if (! $key instanceof Key) {
			throw new StateException('Cannot add a record state without a key to the record state map.');
		}

		$hash = $key->getHash();
		if (isset($this->states[$hash])) {
			if ($this->states[$hash] === $state) {
				return;
			}

			throw new StateException(sprintf("Record state map already contains a different state for key '%s'.", $hash));
		}

		$this->states[$hash] = $state;
	}

	public function remove(Key $key): void
	{
		unset($this->states[$key->getHash()]);
	}

	public function clear(): void
	{
		$this->states = [];
	}

	/**
	 * @return list<RecordState>
	 */
	public function getAll(): array
	{
		return array_values($this->states);
	}
}
