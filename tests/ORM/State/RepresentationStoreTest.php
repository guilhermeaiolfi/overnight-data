<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationStoreTest extends TestCase
{
	public function testAddAndGetByObjectIdentity(): void
	{
		$object = new stdClass();
		$tracked = new RepresentationState(new RepresentationBinding(), []);
		$map = new RepresentationStore();

		$map->add($object, $tracked);

		self::assertTrue($map->has($object));
		self::assertSame($tracked, $map->get($object));
		foreach ($map->getAll() as $representation => $state) {
			self::assertSame($object, $representation);
			self::assertSame($tracked, $state);

			return;
		}

		self::fail('Expected one representation store entry.');
	}

	public function testSameObjectDuplicateWithSameRepresentationStateIsNoOp(): void
	{
		$object = new stdClass();
		$tracked = new RepresentationState(new RepresentationBinding(), []);
		$map = new RepresentationStore();

		$map->add($object, $tracked);
		$map->add($object, $tracked);

		self::assertSame([$tracked], iterator_to_array($map->getAll(), false));
	}

	public function testSameObjectDuplicateWithDifferentRepresentationStateThrows(): void
	{
		$object = new stdClass();
		$map = new RepresentationStore();
		$map->add($object, new RepresentationState(new RepresentationBinding(), []));

		$this->expectException(StateException::class);
		$map->add($object, new RepresentationState(new RepresentationBinding(), []));
	}

	public function testRemoveWorks(): void
	{
		$object = new stdClass();
		$tracked = new RepresentationState(new RepresentationBinding(), []);
		$map = new RepresentationStore();
		$map->add($object, $tracked);

		$map->remove($object);

		self::assertFalse($map->has($object));
	}

	public function testClearWorks(): void
	{
		$object = new stdClass();
		$map = new RepresentationStore();
		$map->add($object, new RepresentationState(new RepresentationBinding(), []));

		$map->clear();

		self::assertSame([], iterator_to_array($map->getAll(), false));
	}

	public function testWeakMapDoesNotKeepRepresentationAlive(): void
	{
		$map = new RepresentationStore();
		$object = new stdClass();
		$map->add($object, new RepresentationState(new RepresentationBinding(), []));

		self::assertTrue($map->has($object));

		unset($object);
		gc_collect_cycles();

		self::assertSame([], iterator_to_array($map->getAll(), false));
	}

	public function testMapDoesNotDiscoverGraphChanges(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: RepresentationStore is an object identity registry only; graph changes must be reported through sync/relation tracking, not discovered by crawling representations.'
		);
	}
}
