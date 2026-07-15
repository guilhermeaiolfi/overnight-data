<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class ToManyRelationStateTest extends TestCase
{
	use OrmFixture;

	public function testCreatesUnloadedCollectionByDefault(): void
	{
		$collection = $this->relatedCollection();

		self::assertTrue($collection->isUnloaded());
		self::assertFalse($collection->isPartiallyLoaded());
		self::assertFalse($collection->isFullyLoaded());
	}

	public function testExposesOwnerRelationNameRelatedSchemaAndState(): void
	{
		$owner = RecordState::new($this->users());
		$schema = $this->postSchema();

		$collection = ToManyRelationState::full(
			$owner,
			'posts',
			$schema
		);

		self::assertSame($owner, $collection->getOwner());
		self::assertSame('posts', $collection->getRelationName());
		self::assertSame($schema, $collection->getRelatedSchema());
		self::assertTrue($collection->isFullyLoaded());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);

		new ToManyRelationState(RecordState::new($this->users()), '', $this->postSchema());
	}

	public function testConstructorRejectsNonObjectItems(): void
	{
		$this->expectException(StateException::class);

		new ToManyRelationState(
			RecordState::new($this->users()),
			'posts',
			$this->postSchema(),
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

		$collection = $this->relatedCollection(fullyLoaded: true, items: [$item]);

		self::assertSame([$item], $collection->getItems());
		self::assertSame([], $collection->getAdded());
	}

	public function testDuplicateInitialItemsByObjectIdentityAreNotDuplicated(): void
	{
		$item = new stdClass();

		$collection = $this->relatedCollection(fullyLoaded: true, items: [$item, $item]);

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
		$existing = new stdClass();
		$item = new stdClass();
		$collection = new ToManyRelationState(
			RecordState::new($this->users()),
			'posts',
			$this->postSchema(),
			[$existing],
		);

		$collection->add($item);

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertSame([$existing, $item], $collection->getItems());
		self::assertSame([$item], $collection->getAdded());
	}

	public function testAddingObjectToFullyLoadedCollectionKeepsFullyLoadedState(): void
	{
		$item = new stdClass();
		$collection = $this->relatedCollection(fullyLoaded: true);

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
		$collection = $this->relatedCollection(fullyLoaded: true, items: [$item]);

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
		$collection = $this->relatedCollection(fullyLoaded: true, items: [$item]);

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
		$collection = $this->relatedCollection();

		$collection->markPartiallyLoaded();

		self::assertTrue($collection->isPartiallyLoaded());
	}

	public function testMarkPartiallyLoadedDoesNotDowngradeFullyLoadedState(): void
	{
		$collection = $this->relatedCollection(fullyLoaded: true);

		$collection->markPartiallyLoaded();

		self::assertTrue($collection->isFullyLoaded());
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
		$collection = $this->relatedCollection(fullyLoaded: true);
		$collection->add($known);
		$collection->remove($removed);

		$collection->clearChanges();

		self::assertSame([$known], $collection->getItems());
		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertFalse($collection->hasChanges());
		self::assertTrue($collection->isFullyLoaded());
	}

	public function testIsEmptyKnownOnlyDescribesInMemoryItemsNotDatabaseEmptiness(): void
	{
		$collection = $this->relatedCollection();

		self::assertTrue($collection->isUnloaded());
		self::assertTrue($collection->isEmptyKnown());
	}

	public function testRelatedSchemaReturnsReusableTemplateAndIsNotMutatedByAddOrRemove(): void
	{
		$schema = $this->postSchema();
		$item = new stdClass();
		$collection = new ToManyRelationState(RecordState::new($this->users()), 'posts', $schema);

		$collection->add($item);
		$collection->remove($item);

		self::assertSame($schema, $collection->getRelatedSchema());
		self::assertSame('posts', $schema->getField('title')->getCollectionName());
	}

	/**
	 * @param list<object> $items
	 */
	private function relatedCollection(
		bool $fullyLoaded = false,
		array $items = [],
	): ToManyRelationState {
		if ($fullyLoaded) {
			return ToManyRelationState::full(RecordState::new($this->users()), 'posts', $this->postSchema(), $items);
		}

		return new ToManyRelationState(RecordState::new($this->users()), 'posts', $this->postSchema(), $items);
	}
}
