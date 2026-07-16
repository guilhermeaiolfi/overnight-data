<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use WeakMap;

final class RepresentationIntentStore
{
	/** @var WeakMap<object, RepresentationIntent> */
	private WeakMap $intents;

	public function __construct()
	{
		$this->intents = new WeakMap();
	}

	public function get(object $representation): ?RepresentationIntent
	{
		return $this->intents[$representation] ?? null;
	}

	public function has(object $representation): bool
	{
		return isset($this->intents[$representation]);
	}

	public function set(object $representation, RepresentationIntent $intent): void
	{
		$this->intents[$representation] = $intent;
	}

	public function ensure(
		object $representation,
		RepresentationIntentLifecycle $lifecycle,
	): RepresentationIntent {
		$existing = $this->get($representation);
		if ($existing instanceof RepresentationIntent) {
			$existing->setLifecycle($lifecycle);

			return $existing;
		}

		$intent = new RepresentationIntent($lifecycle);
		$this->set($representation, $intent);

		return $intent;
	}

	public function isUpdate(object $representation): bool
	{
		$intent = $this->get($representation);

		return $intent instanceof RepresentationIntent && $intent->isUpdate();
	}

	public function remove(object $representation): void
	{
		unset($this->intents[$representation]);
	}

	public function clear(): void
	{
		$this->intents = new WeakMap();
	}
}
