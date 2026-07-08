<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Support;

use LogicException;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class RepresentationStateObjectRegistry
{
	/** @var array<int, object> */
	private static array $objects = [];

	public static function remember(object $representation, RepresentationState $state): RepresentationState
	{
		self::$objects[spl_object_id($state)] = $representation;

		return $state;
	}

	public static function addTo(RepresentationStateStore $store, RepresentationState $state): void
	{
		$store->add(self::objectFor($state), $state);
	}

	public static function objectFor(RepresentationState $state): object
	{
		$object = self::$objects[spl_object_id($state)] ?? null;
		if ($object === null) {
			throw new LogicException('Representation state was not registered with a representation object.');
		}

		return $object;
	}
}
