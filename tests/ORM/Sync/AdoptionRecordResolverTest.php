<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\Sync\AdoptionRecordResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class AdoptionRecordResolverTest extends TestCase
{
	use OrmFixture;

	public function testCreatesNewRecordStateWhenPrimaryKeyCannotBeCompleted(): void
	{
		$representation = $this->representation(['id' => null, 'name' => 'Ada']);
		$records = new RecordStateStore();

		$record = $this->resolver()->resolve($representation, $this->userBindingWithId(), $records, true);

		self::assertTrue($record->isNew());
		self::assertSame('Ada', $record->getValue('name'));
		self::assertFalse($record->hasKey());
	}

	public function testCreatesCleanRecordStateWhenPrimaryKeyCanBeCompleted(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$records = new RecordStateStore();

		$record = $this->resolver()->resolve($representation, $this->userBindingWithId(), $records, true);

		self::assertTrue($record->isClean());
		self::assertSame(10, $record->getKey()?->getFieldValue('id'));
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testReturnsExistingTrackedRecordStateWhenKeyIsAlreadyTracked(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$existing = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'Existing']);
		$records = $this->records($existing);

		$record = $this->resolver()->resolve($representation, $this->userBindingWithId(), $records, true);

		self::assertSame($existing, $record);
		self::assertSame('Existing', $record->getValue('name'));
	}

	public function testRejectsAdoptingRepresentationForRemovedTrackedRecord(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$existing = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'Removed']);
		$existing->markRemoved();
		$records = $this->records($existing);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Cannot adopt representation for collection 'users' because key 'users#id=10' is already tracked as removed.");

		$this->resolver()->resolve($representation, $this->userBindingWithId(), $records, true);
	}

	public function testRejectsRootBindingWithNoTargetCollection(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a binding targeting one collection');

		$this->resolver()->resolve(new stdClass(), new RepresentationBinding(), new RecordStateStore(), true);
	}

	public function testRejectsRelatedBindingWithNoTargetCollection(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('related binding does not target a collection');

		$this->resolver()->resolve(new stdClass(), new RepresentationBinding(), new RecordStateStore(), false);
	}

	public function testRejectsBindingTargetingMultipleCollectionNames(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("path 'title' targets collection 'posts' after 'users'");

		$this->resolver()->resolve(new stdClass(), $binding, new RecordStateStore(), true);
	}

	public function testIgnoresMissingNonKeyPathsWhenBuildingInitialValues(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($this->users(), 'id')));
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));
		$representation = $this->representation(['id' => 10]);

		$record = $this->resolver()->resolve($representation, $binding, new RecordStateStore(), true);

		self::assertTrue($record->isClean());
		self::assertSame(['id' => 10], $record->getValues());
	}

	public function testTreatsMissingKeyPathAsIncompleteKey(): void
	{
		$representation = $this->representation(['name' => 'Ada']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		$record = $this->resolver()->resolve($representation, $binding, new RecordStateStore(), true);

		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
	}

	public function testTreatsNullKeyPathAsIncompleteKey(): void
	{
		$representation = $this->representation(['id' => null, 'name' => 'Ada']);

		$record = $this->resolver()->resolve($representation, $this->userBindingWithId(), new RecordStateStore(), true);

		self::assertTrue($record->isNew());
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testInitialValuesForKeyStartsFromKeyValuesAndMergesBoundPaths(): void
	{
		$key = $this->posts()->getKey(['id' => 123]);
		$representation = $this->representation(['id' => 123, 'title' => 'Draft']);
		$binding = $this->postBindingWithId();

		$values = $this->resolver()->initialValuesForKey($representation, $binding, $key);

		self::assertSame(['id' => 123, 'title' => 'Draft'], $values);
	}

	public function testInitialValuesForKeyIgnoresMissingPaths(): void
	{
		$key = $this->posts()->getKey(['id' => 123]);
		$representation = $this->representation(['id' => 123]);
		$binding = $this->postBindingWithId();

		$values = $this->resolver()->initialValuesForKey($representation, $binding, $key);

		self::assertSame(['id' => 123], $values);
	}

	public function testRelatedBindingRejectsMultipleCollectionNames(): void
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("related binding path 'title' targets collection 'posts' after 'users'");

		$this->resolver()->resolve(new stdClass(), $binding, new RecordStateStore(), false);
	}

	private function resolver(): AdoptionRecordResolver
	{
		return new AdoptionRecordResolver();
	}
}
