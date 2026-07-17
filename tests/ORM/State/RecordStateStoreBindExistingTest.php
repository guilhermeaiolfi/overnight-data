<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RecordStateStoreBindExistingTest extends TestCase
{
	use OrmFixture;

	public function testBindExistingCreatesKeyOnlyCleanThenAppliesPresentFieldsAsDirty(): void
	{
		$key = $this->users()->getKey(10);
		$records = new RecordStateStore();

		$record = $records->bindExisting($key, ['id' => 10, 'name' => 'Ada'], 'removed');

		self::assertTrue($record->isDirty());
		self::assertSame(['name'], $record->getDirtyFields());
		self::assertSame($record, $records->getByKey($key));
	}

	public function testBindExistingPatchesAlreadyTrackedRecord(): void
	{
		$key = $this->users()->getKey(10);
		$existing = RecordState::clean($key, ['id' => 10, 'name' => 'Old']);
		$records = $this->records($existing);

		$record = $records->bindExisting($key, ['name' => 'Ada'], 'removed');

		self::assertSame($existing, $record);
		self::assertSame('Ada', $record->getValue('name'));
		self::assertTrue($record->isDirty());
	}

	public function testBindExistingRejectsRemovedTrackedRecord(): void
	{
		$key = $this->users()->getKey(10);
		$existing = RecordState::clean($key, ['id' => 10, 'name' => 'Old']);
		$existing->markRemoved();
		$records = $this->records($existing);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('already gone');

		$records->bindExisting($key, ['name' => 'Ada'], 'already gone');
	}

	public function testKeyConflictingIdentityFieldDetectsDisagreement(): void
	{
		$key = $this->users()->getKey(10);

		self::assertSame('id', $key->conflictingIdentityField(['id' => 11]));
		self::assertNull($key->conflictingIdentityField([]));
		self::assertNull($key->conflictingIdentityField(['id' => null]));
		self::assertNull($key->conflictingIdentityField(['id' => 10]));
	}
}
