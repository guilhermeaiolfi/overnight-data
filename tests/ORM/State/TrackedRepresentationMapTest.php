<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TrackedRepresentationMapTest extends TestCase
{
	public function testAddAndGetByObjectIdentity(): void
	{
		$object = new stdClass();
		$tracked = new TrackedRepresentation($object, new RepresentationBinding(), []);
		$map = new TrackedRepresentationMap();

		$map->add($tracked);

		self::assertTrue($map->has($object));
		self::assertSame($tracked, $map->get($object));
		self::assertSame([$tracked], $map->getAll());
	}

	public function testSameObjectDuplicateWithSameTrackedRepresentationIsNoOp(): void
	{
		$tracked = new TrackedRepresentation(new stdClass(), new RepresentationBinding(), []);
		$map = new TrackedRepresentationMap();

		$map->add($tracked);
		$map->add($tracked);

		self::assertSame([$tracked], $map->getAll());
	}

	public function testSameObjectDuplicateWithDifferentTrackedRepresentationThrows(): void
	{
		$object = new stdClass();
		$map = new TrackedRepresentationMap();
		$map->add(new TrackedRepresentation($object, new RepresentationBinding(), []));

		$this->expectException(StateException::class);
		$map->add(new TrackedRepresentation($object, new RepresentationBinding(), []));
	}

	public function testRemoveWorks(): void
	{
		$object = new stdClass();
		$tracked = new TrackedRepresentation($object, new RepresentationBinding(), []);
		$map = new TrackedRepresentationMap();
		$map->add($tracked);

		$map->remove($object);

		self::assertFalse($map->has($object));
	}

	public function testClearWorks(): void
	{
		$map = new TrackedRepresentationMap();
		$map->add(new TrackedRepresentation(new stdClass(), new RepresentationBinding(), []));

		$map->clear();

		self::assertSame([], $map->getAll());
	}
}
