<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandPlanner;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;

final class CommandPlannerTest extends TestCase
{
	public function testNewRecordBecomesInsertCommandWithCollectionAndValues(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['name' => 'Ada', 'email' => 'ada@example.test']);

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertSame($users, $command->getCollection());
		self::assertSame(['name' => 'Ada', 'email' => 'ada@example.test'], $command->getValues());
	}

	public function testCleanRecordReturnsNull(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);

		self::assertNull((new CommandPlanner())->plan($record));
	}

	public function testDirtyRecordWithKeyBecomesUpdateCommandWithIdentityAndDirtyChanges(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada', 'email' => 'ada@example.test']);
		$record->setValue('name', 'Grace');

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame($users, $command->getCollection());
		self::assertSame(['id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'Grace'], $command->getChanges());
	}

	public function testDirtyRecordWithCompositeKeyBecomesUpdateCommandWithFullCompositeIdentityInKeyOrder(): void
	{
		$memberships = $this->memberships();
		$record = RecordState::clean(
			$memberships->getKey(['user_id' => 10, 'team_id' => 20]),
			['team_id' => 20, 'user_id' => 10, 'role' => 'member'],
		);
		$record->setValue('role', 'owner');

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame($memberships, $command->getCollection());
		self::assertSame(['team_id' => 20, 'user_id' => 10], $command->getIdentity());
		self::assertSame(['role' => 'owner'], $command->getChanges());
	}

	public function testDirtyRecordWithoutKeyThrows(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$record->markClean();
		$record->setValue('name', 'Grace');

		$this->expectException(InvalidCommandException::class);

		(new CommandPlanner())->plan($record);
	}

	public function testDirtyRecordWithNoDirtyValuesReturnsNull(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->setValue('name', 'Grace');
		$record->setValue('name', 'Ada');

		self::assertTrue($record->isDirty());
		self::assertSame([], $record->getDirtyValues());
		self::assertNull((new CommandPlanner())->plan($record));
	}

	public function testRemovedRecordWithKeyBecomesDeleteCommand(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->markRemoved();

		$command = (new CommandPlanner())->plan($record);

		self::assertInstanceOf(DeleteCommand::class, $command);
		self::assertSame($users, $command->getCollection());
		self::assertSame(['id' => 10], $command->getIdentity());
	}

	public function testRemovedRecordWithoutKeyReturnsNull(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$record->markRemoved();

		self::assertNull((new CommandPlanner())->plan($record));
	}

	public function testPlannerDoesNotMutateRecordState(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$record = RecordState::clean($key, ['id' => 10, 'name' => 'Ada']);
		$record->setValue('name', 'Grace');
		$lifecycle = $record->getLifecycle();
		$values = $record->getValues();
		$originalValues = $record->getOriginalValues();
		$recordKey = $record->getKey();
		$revision = $record->getRevision();

		(new CommandPlanner())->plan($record);

		self::assertSame($lifecycle, $record->getLifecycle());
		self::assertSame($values, $record->getValues());
		self::assertSame($originalValues, $record->getOriginalValues());
		self::assertSame($recordKey, $record->getKey());
		self::assertSame($revision, $record->getRevision());
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

	private function memberships(): CollectionInterface
	{
		return (new Registry())
			->collection('memberships')
			->primaryKey('team_id', 'user_id')
			->field('team_id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('role', 'string')->end();
	}
}
