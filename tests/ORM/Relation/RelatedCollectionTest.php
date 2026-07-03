<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelatedCollectionTest extends TestCase
{
	public function testCreatesUnloadedCollectionByDefault(): void
	{
		$collection = $this->relatedCollection();

		self::assertTrue($collection->isUnloaded());
		self::assertSame(RelationCollectionState::UNLOADED, $collection->getState());
	}

	public function testExposesOwnerRelationNameChildBindingAndState(): void
	{
		$owner = RecordState::new($this->users());
		$binding = $this->postBinding();

		$collection = new RelatedCollection(
			$owner,
			'posts',
			$binding,
			RelationCollectionState::FULLY_LOADED
		);

		self::assertSame($owner, $collection->getOwner());
		self::assertSame('posts', $collection->getRelationName());
		self::assertSame($binding, $collection->getChildBinding());
		self::assertTrue($collection->isFullyLoaded());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);

		new RelatedCollection(RecordState::new($this->users()), '', $this->postBinding());
	}

	public function testConstructorRejectsNonObjectItems(): void
	{
		$this->expectException(StateException::class);

		new RelatedCollection(
			RecordState::new($this->users()),
			'posts',
			$this->postBinding(),
			RelationCollectionState::UNLOADED,
			['not-an-object']
		);
	}

	public function testConstructorWithUnloadedStateAndInitialItemsPromotesToPartiallyLoaded(): void
	{
		$item = new stdClass();

		$collection = $this->relatedCollection(items: [$item]);

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertSame([$item], $collection->getItems());
	}

	public function testInitialItemsAreKnownButNotMarkedAdded(): void
	{
		$item = new stdClass();

		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED, [$item]);

		self::assertSame([$item], $collection->getItems());
		self::assertSame([], $collection->getAdded());
	}

	public function testDuplicateInitialItemsByObjectIdentityAreNotDuplicated(): void
	{
		$item = new stdClass();

		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED, [$item, $item]);

		self::assertSame([$item], $collection->getItems());
		self::assertSame(1, $collection->countKnown());
	}

	public function testAddingObjectToUnloadedCollectionIsAllowedAndMarksPartiallyLoaded(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection();

		$collection->add($item);

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertSame([$item], $collection->getItems());
		self::assertSame([$item], $collection->getAdded());
	}

	public function testAddingObjectToPartiallyLoadedCollectionKeepsPartiallyLoadedState(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection(RelationCollectionState::PARTIALLY_LOADED);

		$collection->add($item);

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertSame([$item], $collection->getItems());
		self::assertSame([$item], $collection->getAdded());
	}

	public function testAddingObjectToFullyLoadedCollectionKeepsFullyLoadedState(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED);

		$collection->add($item);

		self::assertTrue($collection->isFullyLoaded());
		self::assertSame([$item], $collection->getItems());
		self::assertSame([$item], $collection->getAdded());
	}

	public function testAddingSameObjectTwiceIsNoOp(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection();

		$collection->add($item);
		$collection->add($item);

		self::assertSame([$item], $collection->getItems());
		self::assertSame([$item], $collection->getAdded());
	}

	public function testRemovingKnownLoadedObjectRemovesItFromKnownItemsAndRecordsRemoval(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED, [$item]);

		$collection->remove($item);

		self::assertFalse($collection->contains($item));
		self::assertSame([], $collection->getItems());
		self::assertSame([$item], $collection->getRemoved());
	}

	public function testRemovingNewlyAddedObjectCancelsAdditionAndDoesNotRecordRemoval(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection();

		$collection->add($item);
		$collection->remove($item);

		self::assertSame([], $collection->getItems());
		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
	}

	public function testAddingRemovedBaselineObjectCancelsRemovalAndDoesNotMarkAddition(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED, [$item]);

		$collection->remove($item);
		$collection->add($item);

		self::assertSame([$item], $collection->getItems());
		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertFalse($collection->hasChanges());
	}

	public function testRemovingUnknownObjectRecordsExplicitRemovalIntent(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection();

		$collection->remove($item);

		self::assertSame([], $collection->getItems());
		self::assertSame([$item], $collection->getRemoved());
	}

	public function testRemovingFromUnloadedCollectionDoesNotMarkFullyLoadedOrEmptyDatabaseSet(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection();

		$collection->remove($item);

		self::assertTrue($collection->isUnloaded());
		self::assertFalse($collection->isFullyLoaded());
		self::assertTrue($collection->isEmptyKnown());
		self::assertSame([$item], $collection->getRemoved());
	}

	public function testMarkFullyLoadedSetsFullStateButPreservesChanges(): void
	{
		$added = new stdClass();
		$removed = new stdClass();
		$collection = $this->relatedCollection();
		$collection->add($added);
		$collection->remove($removed);

		$collection->markFullyLoaded();

		self::assertTrue($collection->isFullyLoaded());
		self::assertSame([$added], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
	}

	public function testMarkPartiallyLoadedSetsPartialState(): void
	{
		$collection = $this->relatedCollection(RelationCollectionState::FULLY_LOADED);

		$collection->markPartiallyLoaded();

		self::assertTrue($collection->isPartiallyLoaded());
	}

	public function testHasChangesIsTrueWhenAddedIntentExists(): void
	{
		$added = new stdClass();
		$collection = $this->relatedCollection();

		$collection->add($added);

		self::assertTrue($collection->hasChanges());
		self::assertSame([$added], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
	}

	public function testHasChangesIsTrueWhenRemovedIntentExists(): void
	{
		$removed = new stdClass();
		$collection = $this->relatedCollection();

		$collection->remove($removed);

		self::assertTrue($collection->hasChanges());
		self::assertSame([], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
	}

	public function testAccessorsExposeAddedAndRemovedObjects(): void
	{
		$added = new stdClass();
		$removed = new stdClass();
		$collection = $this->relatedCollection();
		$collection->add($added);
		$collection->remove($removed);

		self::assertTrue($collection->hasChanges());
		self::assertSame([$added], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
	}

	public function testHasChangesIsFalseWhenNoAddedOrRemovedIntentExists(): void
	{
		self::assertFalse($this->relatedCollection()->hasChanges());
	}

	public function testClearChangesClearsAddedAndRemovedButKeepsKnownItems(): void
	{
		$known = new stdClass();
		$removed = new stdClass();
		$collection = $this->relatedCollection();
		$collection->add($known);
		$collection->remove($removed);

		$collection->clearChanges();

		self::assertSame([$known], $collection->getItems());
		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertFalse($collection->hasChanges());
	}

	public function testIsEmptyKnownOnlyDescribesInMemoryItemsNotDatabaseEmptiness(): void
	{
		$collection = $this->relatedCollection();

		self::assertTrue($collection->isUnloaded());
		self::assertTrue($collection->isEmptyKnown());
	}

	public function testGetChildBindingReturnsReusableTemplateAndIsNotMutatedByAddOrRemove(): void
	{
		$binding = $this->postBinding();
		$item = new stdClass();
		$collection = new RelatedCollection(RecordState::new($this->users()), 'posts', $binding);

		$collection->add($item);
		$collection->remove($item);

		self::assertSame($binding, $collection->getChildBinding());
		self::assertTrue($binding->get('title')->getField()->isTemplate());
	}

	/**
	 * @param list<object> $items
	 */
	private function relatedCollection(
		RelationCollectionState $state = RelationCollectionState::UNLOADED,
		array $items = [],
	): RelatedCollection {
		return new RelatedCollection(RecordState::new($this->users()), 'posts', $this->postBinding(), $state, $items);
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
