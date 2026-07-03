<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordLifecycle;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;

final class RecordStateTest extends TestCase
{
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
		self::assertSame('users', $state->getCollectionName());
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

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();
	}
}
