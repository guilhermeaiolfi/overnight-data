<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use WeakMap;

final class ExistingIntentStore
{
	/** @var WeakMap<object, true> */
	private WeakMap $marked;

	public function __construct()
	{
		$this->marked = new WeakMap();
	}

	public function mark(object $representation): void
	{
		$this->marked[$representation] = true;
	}

	public function isMarked(object $representation): bool
	{
		return isset($this->marked[$representation]);
	}

	public function clear(): void
	{
		$this->marked = new WeakMap();
	}
}
