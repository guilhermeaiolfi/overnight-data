<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordStateMap
{
	/** @var array<string, RecordState> */
	private array $statesByHash = [];

	/** @var array<string, RecordState> */
	private array $statesByKeyHash = [];

	public function has(Key $key): bool
	{
		return $this->hasKey($key);
	}

	public function get(Key $key): ?RecordState
	{
		return $this->getByKey($key);
	}

	public function add(RecordState $state): void
	{
		$stateHash = $state->getStateHash();
		if (isset($this->statesByHash[$stateHash])) {
			if ($this->statesByHash[$stateHash] !== $state) {
				throw new StateException(sprintf("Record state map already contains a different state for state hash '%s'.", $stateHash));
			}
		} else {
			$this->statesByHash[$stateHash] = $state;
		}

		if ($state->hasKey()) {
			$this->indexKey($state);
		}
	}

	public function hasStateHash(string $stateHash): bool
	{
		return array_key_exists($stateHash, $this->statesByHash);
	}

	public function getByStateHash(string $stateHash): ?RecordState
	{
		return $this->statesByHash[$stateHash] ?? null;
	}

	public function hasKey(Key $key): bool
	{
		return array_key_exists($key->getHash(), $this->statesByKeyHash);
	}

	public function getByKey(Key $key): ?RecordState
	{
		return $this->statesByKeyHash[$key->getHash()] ?? null;
	}

	public function indexKey(RecordState $state): void
	{
		$key = $state->getKey();
		if (! $key instanceof Key) {
			throw new StateException('Cannot index a record state without a key in the record state map.');
		}

		$stateHash = $state->getStateHash();
		if (isset($this->statesByHash[$stateHash]) && $this->statesByHash[$stateHash] !== $state) {
			throw new StateException(sprintf("Record state map already contains a different state for state hash '%s'.", $stateHash));
		}

		$this->statesByHash[$stateHash] = $state;

		$hash = $key->getHash();
		if (isset($this->statesByKeyHash[$hash])) {
			if ($this->statesByKeyHash[$hash] === $state) {
				return;
			}

			throw new StateException(sprintf("Record state map already contains a different state for key '%s'.", $hash));
		}

		$this->statesByKeyHash[$hash] = $state;
	}

	public function getForField(RecordFieldRef $field): ?RecordState
	{
		if ($field->hasState()) {
			return $field->getState();
		}

		$key = $field->getKey();
		if ($key instanceof Key) {
			return $this->getByKey($key);
		}

		return null;
	}

	public function getFromRepresentation(TrackedRepresentation $tracked): ?RecordState
	{
		$state = null;
		foreach ($tracked->getBinding()->getAll() as $binding) {
			$resolved = $this->getForField($binding->getField());
			if (! $resolved instanceof RecordState) {
				continue;
			}

			if ($state === null) {
				$state = $resolved;

				continue;
			}

			if ($state !== $resolved) {
				throw new StateException('Tracked representation cannot be collapsed to one record.');
			}
		}

		return $state;
	}

	public function requireForField(RecordFieldRef $field): RecordState
	{
		$state = $this->getForField($field);
		if ($state instanceof RecordState) {
			return $state;
		}

		throw new StateException(sprintf("Record state map cannot resolve record state for field '%s.%s'.", $field->getCollectionName(), $field->getFieldName()));
	}

	public function remove(Key $key): void
	{
		unset($this->statesByKeyHash[$key->getHash()]);
	}

	public function removeState(RecordState $state): void
	{
		$stateHash = $state->getStateHash();
		if (($this->statesByHash[$stateHash] ?? null) === $state) {
			unset($this->statesByHash[$stateHash]);
		}

		$key = $state->getKey();
		if ($key instanceof Key && ($this->statesByKeyHash[$key->getHash()] ?? null) === $state) {
			unset($this->statesByKeyHash[$key->getHash()]);
		}
	}

	public function clear(): void
	{
		$this->statesByHash = [];
		$this->statesByKeyHash = [];
	}

	/**
	 * @return list<RecordState>
	 */
	public function getAll(): array
	{
		return array_values($this->statesByHash);
	}
}
