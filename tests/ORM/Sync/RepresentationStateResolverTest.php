<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\RepresentationStateResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationStateResolverTest extends TestCase
{
	public function testgetRepresentationStateReturnsRepresentationStateForTrackedObject(): void
	{
		$object = new stdClass();
		$tracked = new RepresentationState(new RepresentationBinding(), []);
		$map = new RepresentationStore();
		$map->add($object, $tracked);

		self::assertSame($tracked, (new RepresentationStateResolver($map))->getRepresentationState($object, 'posts'));
	}

	public function testgetRepresentationStateThrowsSyncExceptionForUntrackedObject(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('not tracked');

		(new RepresentationStateResolver(new RepresentationStore()))->getRepresentationState(new stdClass(), 'posts');
	}

	public function testThrownMessageIncludesRelationPath(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.profile');

		(new RepresentationStateResolver(new RepresentationStore()))->getRepresentationState(new stdClass(), 'author.profile');
	}

	public function testResolverDoesNotAutoAdoptOrCreateRepresentationStates(): void
	{
		$map = new RepresentationStore();
		$object = new stdClass();

		try {
			(new RepresentationStateResolver($map))->getRepresentationState($object, 'posts');
		} catch (SyncException) {
		}

		self::assertSame([], iterator_to_array($map->getAll(), false));
		self::assertNull($map->get($object));
	}
}
