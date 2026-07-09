<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordLifecycle;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\ValueRef;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RecordStateTest extends TestCase
{
	use OrmFixture;

	public function testNewCreatesNewStateWithRevisionOne(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);

		self::assertTrue($state->isNew());
		self::assertSame(RecordLifecycle::NEW, $state->getLifecycle());
		self::assertSame(1, $state->getRevision());
		self::assertFalse($state->hasKey());
	}

	public function testNewRecordsInitialValuesInHistory(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);

		self::assertSame(['name' => 'A1'], $state->getHistory()->getSnapshot(1));
	}

	public function testNewRecordHasNonEmptyStableStateHash(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$hash = $state->getStateHash();

		self::assertNotSame('', $hash);
		self::assertSame($hash, $state->getStateHash());
	}

	public function testTwoNewRecordsFromSameCollectionHaveDifferentStateHashes(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['name' => 'A1']);
		$second = RecordState::new($users, ['name' => 'A2']);

		self::assertNotSame($first->getStateHash(), $second->getStateHash());
	}

	public function testCleanCreatesCleanStateWithExistingKey(): void
	{
		$key = $this->users()->getKey(10);
		$state = RecordState::clean($key, ['id' => 10, 'name' => 'A1']);

		self::assertTrue($state->isClean());
		self::assertSame($key, $state->getKey());
		self::assertSame('users', $state->getCollection()->getName());
	}

	public function testCleanRecordsInitialValuesInHistoryRevisionOne(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);

		self::assertSame(['id' => 10, 'name' => 'A1'], $state->getHistory()->getSnapshot(1));
	}

	public function testCleanRecordHasNonEmptyStateHash(): void
	{
		$key = $this->users()->getKey(10);
		$state = RecordState::clean($key, ['id' => 10, 'name' => 'A1']);

		self::assertSame($key->getHash(), $state->getStateHash());
		self::assertNotSame('', $state->getStateHash());
	}

	public function testSettingSameValueDoesNotBumpRevision(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);

		$state->setValue('name', 'A1');

		self::assertSame(1, $state->getRevision());
		self::assertTrue($state->isClean());
	}

	public function testChangingOneValueBumpsRevisionOnce(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);

		$state->setValue('name', 'A2');

		self::assertSame(2, $state->getRevision());
		self::assertTrue($state->isDirty());
		self::assertSame('A2', $state->getHistory()->getValue(2, 'name'));
	}

	public function testSetValuesChangesMultipleFieldsInOneRevision(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1', 'email' => 'a@example.test']);

		$state->setValues(['name' => 'A2', 'email' => 'b@example.test']);

		self::assertSame(2, $state->getRevision());
		self::assertSame(['name' => 'A2', 'email' => 'b@example.test'], $state->getValues());
	}

	public function testDirtyFieldsCompareCurrentValuesAgainstOriginalValues(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1', 'email' => 'a@example.test']);

		$state->setValue('name', 'A2');

		self::assertSame(['name'], $state->getDirtyFields());
		self::assertSame(['name' => 'A2'], $state->getDirtyValues());
	}

	public function testNewRecordKnownValuesAreDirty(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);

		self::assertSame(['name'], $state->getDirtyFields());
		self::assertSame(['name' => 'A1'], $state->getDirtyValues());
	}

	public function testMissingFieldIsNotTreatedAsNull(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => null]);

		self::assertTrue($state->hasValue('name'));
		self::assertFalse($state->hasValue('email'));

		$this->expectException(StateException::class);
		$state->getValue('email');
	}

	public function testMarkCleanUpdatesOriginalValuesAndClearsDirtyFields(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$state->setValue('name', 'A2');

		$state->markClean();

		self::assertTrue($state->isClean());
		self::assertSame(['name' => 'A2'], $state->getOriginalValues());
		self::assertSame([], $state->getDirtyFields());
	}

	public function testMarkCleanAssignsKey(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['id' => 10, 'name' => 'A1']);

		$state->markClean($users->getKey(10));

		self::assertTrue($state->hasKey());
		self::assertSame(['id' => 10], $state->getKey()?->getValues());
	}

	public function testMarkCleanAssigningKeyDoesNotChangeExistingStateHash(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['id' => 10, 'name' => 'A1']);
		$hash = $state->getStateHash();

		$state->markClean($users->getKey(10));

		self::assertSame($hash, $state->getStateHash());
	}

	public function testMarkRemovedMarksRemoved(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);

		$state->markRemoved();

		self::assertTrue($state->isRemoved());
	}

	public function testGetValueRefReturnsRefForStateAndField(): void
	{
		$state = RecordState::new($this->users());
		$ref = $state->getValueRef('id');

		self::assertSame($state, $ref->getRecord());
		self::assertSame('id', $ref->getField());
	}

	public function testSetValueWithResolvedValueRefStoresConcreteValue(): void
	{
		$source = RecordState::new($this->users(), ['id' => 10]);
		$target = RecordState::new($this->users());

		$target->setValue('user_id', $source->getValueRef('id'));

		self::assertSame(10, $target->getValue('user_id'));
	}

	public function testSetValueWithUnresolvedValueRefStoresRef(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users());
		$ref = $source->getValueRef('id');

		$target->setValue('user_id', $ref);

		self::assertSame($ref, $target->getValue('user_id'));
	}

	public function testGetValueReturnsStoredUnresolvedValueRefWithoutResolving(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users());
		$target->setValue('user_id', $source->getValueRef('id'));

		$stored = $target->getValue('user_id');

		self::assertInstanceOf(ValueRef::class, $stored);
		self::assertFalse($stored->isResolved());
	}

	public function testResolveValueRefsReplacesNowResolvedRefsWithConcreteValues(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users());
		$target->setValue('user_id', $source->getValueRef('id'));

		$source->setValue('id', 10);

		self::assertTrue($target->resolveValueRefs());
		self::assertSame(10, $target->getValue('user_id'));
	}

	public function testResolveValueRefsReturnsFalseWhenNothingChanges(): void
	{
		$state = RecordState::new($this->users(), ['id' => 10]);

		self::assertFalse($state->resolveValueRefs());
	}

	public function testUnresolvedValueRefInspection(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users());
		$ref = $source->getValueRef('id');
		$target->setValue('user_id', $ref);

		self::assertTrue($target->hasValueRefs());
		self::assertTrue($target->hasUnresolvedValueRefs());
		self::assertSame(['user_id' => $ref], $target->getUnresolvedValueRefs());
	}

	public function testConcreteNullIsStoredDirectlyButValueRefToNullSourceIsUnresolved(): void
	{
		$source = RecordState::new($this->users(), ['id' => null]);
		$target = RecordState::new($this->users(), ['user_id' => null]);

		self::assertSame(null, $target->getValue('user_id'));

		$target->setValue('user_id', $source->getValueRef('id'));

		self::assertInstanceOf(ValueRef::class, $target->getValue('user_id'));
		self::assertTrue($target->hasUnresolvedValueRefs());
	}

	public function testSelfReferenceDoesNotInfiniteLoopAndRemainsUnresolved(): void
	{
		$state = RecordState::new($this->users());
		$state->setValue('id', $state->getValueRef('id'));

		self::assertFalse($state->resolveValueRefs());
		self::assertInstanceOf(ValueRef::class, $state->getValue('id'));
		self::assertTrue($state->hasUnresolvedValueRefs());
	}

	public function testSettingSameUnresolvedValueRefTwiceDoesNotBumpRevision(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::new($this->users());

		$target->setValue('user_id', $source->getValueRef('id'));
		$revision = $target->getRevision();
		$target->setValue('user_id', $source->getValueRef('id'));

		self::assertSame($revision, $target->getRevision());
	}

	public function testDirtyValuesIncludeValueRefBeforeResolutionAndConcreteValueAfterResolution(): void
	{
		$source = RecordState::new($this->users());
		$target = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'user_id' => null]);
		$target->setValue('user_id', $source->getValueRef('id'));

		self::assertInstanceOf(ValueRef::class, $target->getDirtyValues()['user_id']);

		$source->setValue('id', 20);
		self::assertTrue($target->resolveValueRefs());

		self::assertSame(['user_id' => 20], $target->getDirtyValues());
	}
}
