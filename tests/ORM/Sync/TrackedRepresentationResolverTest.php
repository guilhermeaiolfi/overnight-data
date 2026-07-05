<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\TrackedRepresentationResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TrackedRepresentationResolverTest extends TestCase
{
	public function testGetTrackedRepresentationReturnsTrackedRepresentationForTrackedObject(): void
	{
		$object = new stdClass();
		$tracked = new TrackedRepresentation($object, new RepresentationBinding(), []);
		$map = new TrackedRepresentationMap();
		$map->add($tracked);

		self::assertSame($tracked, (new TrackedRepresentationResolver($map))->getTrackedRepresentation($object, 'posts'));
	}

	public function testGetTrackedRepresentationThrowsSyncExceptionForUntrackedObject(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('not tracked');

		(new TrackedRepresentationResolver(new TrackedRepresentationMap()))->getTrackedRepresentation(new stdClass(), 'posts');
	}

	public function testThrownMessageIncludesRelationPath(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.profile');

		(new TrackedRepresentationResolver(new TrackedRepresentationMap()))->getTrackedRepresentation(new stdClass(), 'author.profile');
	}

	public function testResolverDoesNotAutoAdoptOrCreateTrackedRepresentations(): void
	{
		$map = new TrackedRepresentationMap();
		$object = new stdClass();

		try {
			(new TrackedRepresentationResolver($map))->getTrackedRepresentation($object, 'posts');
		} catch (SyncException) {
		}

		self::assertSame([], $map->getAll());
		self::assertNull($map->get($object));
	}
}
