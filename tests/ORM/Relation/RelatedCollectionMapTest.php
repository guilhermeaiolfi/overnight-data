<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelatedCollectionMapTest extends TestCase
{
	public function testAddGetAndHasByOwnerAndRelationName(): void
	{
		$owner = RecordState::new($this->users());
		$collection = $this->relatedCollection($owner, 'posts');
		$map = new RelatedCollectionMap();

		$map->add($collection);

		self::assertTrue($map->has($owner, 'posts'));
		self::assertSame($collection, $map->get($owner, 'posts'));
	}

	public function testAddingSameInstanceTwiceIsIdempotent(): void
	{
		$collection = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$map = new RelatedCollectionMap();

		$map->add($collection);
		$map->add($collection);

		self::assertSame([$collection], $map->getAll());
	}

	public function testAddingDifferentCollectionForSameOwnerAndRelationThrows(): void
	{
		$owner = RecordState::new($this->users());
		$map = new RelatedCollectionMap();
		$map->add($this->relatedCollection($owner, 'posts'));

		$this->expectException(StateException::class);

		$map->add($this->relatedCollection($owner, 'posts'));
	}

	public function testGetAllPreservesInsertionOrder(): void
	{
		$first = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$second = $this->relatedCollection(RecordState::new($this->users()), 'comments');
		$map = new RelatedCollectionMap();

		$map->add($first);
		$map->add($second);

		self::assertSame([$first, $second], $map->getAll());
	}

	public function testGetChangedReturnsOnlyCollectionsWithChanges(): void
	{
		$unchanged = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$changed = $this->relatedCollection(RecordState::new($this->users()), 'comments');
		$changed->add(new stdClass());
		$map = new RelatedCollectionMap();

		$map->add($unchanged);
		$map->add($changed);

		self::assertSame([$changed], $map->getChanged());
	}

	public function testRemoveAndClearWork(): void
	{
		$owner = RecordState::new($this->users());
		$first = $this->relatedCollection($owner, 'posts');
		$second = $this->relatedCollection(RecordState::new($this->users()), 'comments');
		$map = new RelatedCollectionMap();
		$map->add($first);
		$map->add($second);

		$map->remove($owner, 'posts');

		self::assertFalse($map->has($owner, 'posts'));
		self::assertSame([$second], $map->getAll());

		$map->clear();

		self::assertSame([], $map->getAll());
	}

	private function relatedCollection(RecordState $owner, string $relationName): RelatedCollection
	{
		return new RelatedCollection($owner, $relationName, $this->postBinding());
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end();
	}
}
