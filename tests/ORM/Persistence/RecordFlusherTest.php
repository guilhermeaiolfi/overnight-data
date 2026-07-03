<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\RecordFlusher;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class RecordFlusherTest extends TestCase
{
	public function testCleanRecordsAreSkippedAndNoCommandIsExecuted(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		$results = (new RecordFlusher($executor))->flush($states);

		self::assertSame([], $results);
		self::assertSame([], $executor->getCommands());
		self::assertTrue($record->isClean());
	}

	public function testNewRecordExecutesInsertCommandAndBecomesClean(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		$results = (new RecordFlusher($executor))->flush($states);

		self::assertCount(1, $results);
		self::assertContainsOnlyInstancesOf(InsertCommand::class, $executor->getCommands());
		self::assertTrue($record->isClean());
		self::assertNull($record->getKey());
	}

	public function testNewRecordWithApplicationAssignedPrimaryKeyIsIndexedAfterFlush(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);
		$states = $this->states($record);

		(new RecordFlusher(new RecordingCommandExecutor()))->flush($states);

		$key = $users->getKey(10);
		self::assertTrue($record->isClean());
		self::assertTrue($record->hasKey());
		self::assertSame($record, $states->getByKey($key));
	}

	public function testNewRecordWithGeneratedPrimaryKeyValuesMergesGeneratedValuesAndIsIndexed(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor(new CommandResult(1, ['id' => 10]));

		(new RecordFlusher($executor))->flush($states);

		$key = $users->getKey(10);
		self::assertTrue($record->isClean());
		self::assertSame(['name' => 'Ada', 'id' => 10], $record->getValues());
		self::assertSame($record, $states->getByKey($key));
	}

	public function testDirtyRecordExecutesUpdateCommandAndBecomesClean(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->setValue('name', 'Grace');
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		(new RecordFlusher($executor))->flush($states);

		self::assertContainsOnlyInstancesOf(UpdateCommand::class, $executor->getCommands());
		self::assertTrue($record->isClean());
		self::assertSame(['id' => 10, 'name' => 'Grace'], $record->getOriginalValues());
		self::assertSame($record, $states->getByKey($users->getKey(10)));
	}

	public function testRemovedRecordWithKeyExecutesDeleteCommandAndIsRemovedFromMap(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->markRemoved();
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		(new RecordFlusher($executor))->flush($states);

		self::assertContainsOnlyInstancesOf(DeleteCommand::class, $executor->getCommands());
		self::assertSame([], $states->getAll());
		self::assertTrue($record->isRemoved());
	}

	public function testRemovedNewRecordWithoutKeyExecutesNoCommandAndIsRemovedFromMap(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$record->markRemoved();
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		$results = (new RecordFlusher($executor))->flush($states);

		self::assertSame([], $results);
		self::assertSame([], $executor->getCommands());
		self::assertSame([], $states->getAll());
	}

	public function testFlushReturnsCommandResultsInExecutionOrder(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['name' => 'Ada']);
		$second = RecordState::clean($users->getKey(20), ['id' => 20, 'name' => 'Grace']);
		$second->setValue('name', 'Katherine');
		$firstResult = new CommandResult(1, ['id' => 10]);
		$secondResult = new CommandResult(2);
		$executor = new RecordingCommandExecutor(results: [$firstResult, $secondResult]);

		$results = (new RecordFlusher($executor))->flush($this->states($first, $second));

		self::assertSame([$firstResult, $secondResult], $results);
	}

	public function testMultipleStatesAreFlushedInRecordStateMapInsertionOrder(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);
		$second = RecordState::new($users, ['id' => 20, 'name' => 'Grace']);
		$executor = new RecordingCommandExecutor();

		(new RecordFlusher($executor))->flush($this->states($first, $second));

		self::assertSame(
			[
				['id' => 10, 'name' => 'Ada'],
				['id' => 20, 'name' => 'Grace'],
			],
			array_map(
				static fn (CommandInterface $command): array => $command instanceof InsertCommand ? $command->getValues() : [],
				$executor->getCommands(),
			),
		);
	}

	public function testExecutorExceptionBubblesAndFailedRecordIsNotMarkedClean(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('executor failed');
			}
		};

		$this->expectException(LogicException::class);

		try {
			(new RecordFlusher($executor))->flush($states);
		} finally {
			self::assertTrue($record->isNew());
			self::assertNull($record->getKey());
			self::assertSame($record, $states->getAll()[0] ?? null);
		}
	}

	public function testFlushUsesNeutralCommandsWithoutSqlSpecificBehavior(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$executor = new RecordingCommandExecutor();

		(new RecordFlusher($executor))->flush($this->states($record));

		self::assertContainsOnlyInstancesOf(CommandInterface::class, $executor->getCommands());
		self::assertFalse(method_exists($executor->getCommands()[0], 'getSql'));
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function states(RecordState ...$records): RecordStateMap
	{
		$states = new RecordStateMap();
		foreach ($records as $record) {
			$states->add($record);
		}

		return $states;
	}
}
