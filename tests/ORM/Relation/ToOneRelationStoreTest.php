<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ToOneRelationStoreTest extends TestCase
{
	public function testAddGetAndHasByOwnerAndRelationName(): void
	{
		$owner = RecordState::new($this->users());
		$reference = $this->relatedReference($owner, 'author');
		$map = new ToOneRelationStore();

		$map->add($reference);

		self::assertTrue($map->has($owner, 'author'));
		self::assertSame($reference, $map->get($owner, 'author'));
	}

	public function testAddingSameInstanceTwiceIsIdempotent(): void
	{
		$reference = $this->relatedReference(RecordState::new($this->users()), 'author');
		$map = new ToOneRelationStore();

		$map->add($reference);
		$map->add($reference);

		self::assertSame([$reference], $map->getAll());
	}

	public function testAddingDifferentReferenceForSameOwnerAndRelationThrows(): void
	{
		$owner = RecordState::new($this->users());
		$map = new ToOneRelationStore();
		$map->add($this->relatedReference($owner, 'author'));

		$this->expectException(StateException::class);

		$map->add($this->relatedReference($owner, 'author'));
	}

	public function testGetAllPreservesInsertionOrder(): void
	{
		$first = $this->relatedReference(RecordState::new($this->users()), 'author');
		$second = $this->relatedReference(RecordState::new($this->users()), 'profile');
		$map = new ToOneRelationStore();

		$map->add($first);
		$map->add($second);

		self::assertSame([$first, $second], $map->getAll());
	}

	public function testGetChangedReturnsOnlyChangedReferences(): void
	{
		$unchanged = $this->relatedReference(RecordState::new($this->users()), 'author');
		$changed = $this->relatedReference(RecordState::new($this->users()), 'profile');
		$changed->set(new stdClass());
		$map = new ToOneRelationStore();

		$map->add($unchanged);
		$map->add($changed);

		self::assertSame([$changed], $map->getChanged());
	}

	public function testRemoveAndClearWork(): void
	{
		$owner = RecordState::new($this->users());
		$first = $this->relatedReference($owner, 'author');
		$second = $this->relatedReference(RecordState::new($this->users()), 'profile');
		$map = new ToOneRelationStore();
		$map->add($first);
		$map->add($second);

		$map->remove($owner, 'author');

		self::assertFalse($map->has($owner, 'author'));
		self::assertSame([$second], $map->getAll());

		$map->clear();

		self::assertSame([], $map->getAll());
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
