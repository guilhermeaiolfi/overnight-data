<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\ORM\Exception\StateException;
use WeakMap;

final class RepresentationStateStore
{
	/** @var WeakMap<object, RepresentationState> */
	private WeakMap $states;

	public function __construct()
	{
		$this->states = new WeakMap();
	}

	public function has(object $representation): bool
	{
		return isset($this->states[$representation]);
	}

	public function get(object $representation): ?RepresentationState
	{
		return $this->states[$representation] ?? null;
	}

	public function add(object $representation, RepresentationState $state): void
	{
		if (isset($this->states[$representation])) {
			if ($this->states[$representation] === $state) {
				return;
			}

			throw new StateException('Representation state store already contains a different state for this object.');
		}

		$this->states[$representation] = $state;
	}

	public function remove(object $representation): void
	{
		unset($this->states[$representation]);
	}

	public function clear(): void
	{
		$this->states = new WeakMap();
	}

	/**
	 * @return iterable<object, RepresentationState>
	 */
	public function getAll(): iterable
	{
		return $this->states;
	}
}
