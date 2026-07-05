<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelationStateStoreTest extends TestCase
{
	public function testStoresAndRetrievesToManyRelationState(): void
	{
		$owner = RecordState::new($this->users());
		$collection = $this->relatedCollection($owner, 'posts');
		$store = new RelationStateStore();

		$store->add($collection);

		self::assertTrue($store->has($owner, 'posts'));
		self::assertSame($collection, $store->get($owner, 'posts'));
	}

	public function testStoresAndRetrievesToOneRelationState(): void
	{
		$owner = RecordState::new($this->users());
		$reference = $this->relatedReference($owner, 'author');
		$store = new RelationStateStore();

		$store->add($reference);

		self::assertTrue($store->has($owner, 'author'));
		self::assertSame($reference, $store->get($owner, 'author'));
	}

	public function testAddingSameInstanceTwiceIsIdempotentForToManyState(): void
	{
		$collection = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$store = new RelationStateStore();

		$store->add($collection);
		$store->add($collection);

		self::assertSame([$collection], $store->getAll());
	}

	public function testAddingSameInstanceTwiceIsIdempotentForToOneState(): void
	{
		$reference = $this->relatedReference(RecordState::new($this->users()), 'author');
		$store = new RelationStateStore();

		$store->add($reference);
		$store->add($reference);

		self::assertSame([$reference], $store->getAll());
	}

	public function testAddingDifferentToManyStateForSameOwnerAndRelationThrows(): void
	{
		$owner = RecordState::new($this->users());
		$store = new RelationStateStore();
		$store->add($this->relatedCollection($owner, 'posts'));

		$this->expectException(StateException::class);

		$store->add($this->relatedCollection($owner, 'posts'));
	}

	public function testAddingDifferentToOneStateForSameOwnerAndRelationThrows(): void
	{
		$owner = RecordState::new($this->users());
		$store = new RelationStateStore();
		$store->add($this->relatedReference($owner, 'author'));

		$this->expectException(StateException::class);

		$store->add($this->relatedReference($owner, 'author'));
	}

	public function testGetAllPreservesInsertionOrder(): void
	{
		$first = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$second = $this->relatedReference(RecordState::new($this->users()), 'author');
		$store = new RelationStateStore();

		$store->add($first);
		$store->add($second);

		self::assertSame([$first, $second], $store->getAll());
	}

	public function testGetChangedReturnsOnlyStatesWithChanges(): void
	{
		$unchangedCollection = $this->relatedCollection(RecordState::new($this->users()), 'posts');
		$changedCollection = $this->relatedCollection(RecordState::new($this->users()), 'comments');
		$changedCollection->add(new stdClass());
		$unchangedReference = $this->relatedReference(RecordState::new($this->users()), 'author');
		$changedReference = $this->relatedReference(RecordState::new($this->users()), 'profile');
		$changedReference->set(new stdClass());
		$store = new RelationStateStore();

		$store->add($unchangedCollection);
		$store->add($changedCollection);
		$store->add($unchangedReference);
		$store->add($changedReference);

		self::assertSame([$changedCollection, $changedReference], $store->getChanged());
	}

	public function testRemoveAndClearWork(): void
	{
		$owner = RecordState::new($this->users());
		$first = $this->relatedCollection($owner, 'posts');
		$second = $this->relatedReference(RecordState::new($this->users()), 'author');
		$store = new RelationStateStore();
		$store->add($first);
		$store->add($second);

		$store->remove($owner, 'posts');

		self::assertFalse($store->has($owner, 'posts'));
		self::assertSame([$second], $store->getAll());

		$store->clear();

		self::assertSame([], $store->getAll());
	}

	public function testEmptyRelationNameIsRejected(): void
	{
		$owner = RecordState::new($this->users());
		$store = new RelationStateStore();
		$store->add($this->relatedCollection($owner, 'posts'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('Relation name cannot be empty.');

		$store->has($owner, '');
	}

	private function relatedCollection(RecordState $owner, string $relationName): ToManyRelationState
	{
		return new ToManyRelationState($owner, $relationName, $this->postBinding());
	}

	private function relatedReference(RecordState $owner, string $relationName): ToOneRelationState
	{
		return new ToOneRelationState($owner, $relationName, $this->postBinding());
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

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
