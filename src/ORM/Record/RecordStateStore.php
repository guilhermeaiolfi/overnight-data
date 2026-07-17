<?php

declare(strict_types=1);

namespace ON\Data\ORM\Record;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordStateStore
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
				throw new StateException(sprintf("Record state store already contains a different state for state hash '%s'.", $stateHash));
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

	/**
	 * Tracked record for $key, or null when absent. Throws when the key is tracked as removed.
	 */
	public function getActive(Key $key, string $removedMessage): ?RecordState
	{
		$record = $this->getByKey($key);
		if (! $record instanceof RecordState) {
			return null;
		}

		if ($record->isRemoved()) {
			throw new StateException($removedMessage);
		}

		return $record;
	}

	/**
	 * PATCH an existing row: reuse or create a key-only clean record, then set present values.
	 *
	 * @param array<string, mixed> $presentValues
	 */
	public function bindExisting(Key $key, array $presentValues, string $removedMessage): RecordState
	{
		$existing = $this->getActive($key, $removedMessage);
		if ($existing instanceof RecordState) {
			$existing->setValues($presentValues);

			return $existing;
		}

		$record = RecordState::clean($key, $key->getValues());
		$this->add($record);
		$record->setValues($presentValues);

		return $record;
	}

	public function indexKey(RecordState $state): void
	{
		$key = $state->getKey();
		if (! $key instanceof Key) {
			throw new StateException('Cannot index a record state without a key in the record state store.');
		}

		$stateHash = $state->getStateHash();
		if (isset($this->statesByHash[$stateHash]) && $this->statesByHash[$stateHash] !== $state) {
			throw new StateException(sprintf("Record state store already contains a different state for state hash '%s'.", $stateHash));
		}

		$this->statesByHash[$stateHash] = $state;

		$hash = $key->getHash();
		if (isset($this->statesByKeyHash[$hash])) {
			if ($this->statesByKeyHash[$hash] === $state) {
				return;
			}

			throw new StateException(sprintf("Record state store already contains a different state for key '%s'.", $hash));
		}

		$this->statesByKeyHash[$hash] = $state;
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
