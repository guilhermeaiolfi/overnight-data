<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class FlushExecutorTest extends TestCase
{
	public function testFlushSynchronizesRepresentationChangesBeforePersistencePlanning(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->trackedMap($tracked), $this->records($record));

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame(['name' => 'A2'], $command->getChanges());
	}

	public function testFlushReturnsSyncPlansAndCommandResults(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$commandResult = new CommandResult(1, ['id' => 10]);
		$executor = new RecordingCommandExecutor($commandResult);

		$result = (new FlushExecutor($executor))->flush($this->trackedMap($tracked), $this->records($record));

		self::assertCount(1, $result->getSyncPlans());
		self::assertCount(1, $result->getSyncPlans()[0]->getUpdates());
		self::assertSame([$commandResult], $result->getCommandResults());
	}

	public function testFlushStillFlushesDirtyNewAndRemovedRecordsWhenThereAreNoRepresentationChanges(): void
	{
		$users = $this->users();
		$new = RecordState::new($users, ['id' => 20, 'name' => 'New']);
		$dirty = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$dirty->setValue('name', 'A2');
		$removed = RecordState::clean($users->getKey(30), ['id' => 30, 'name' => 'Removed']);
		$removed->markRemoved();
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1),
			new CommandResult(1),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush(new TrackedRepresentationMap(), $this->records($new, $dirty, $removed));

		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[1]);
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[2]);
	}

	public function testSyncExceptionPreventsCommandExecution(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$record->setValue('name', 'A2');
		$executor = new RecordingCommandExecutor();

		$this->expectException(SyncException::class);

		try {
			(new FlushExecutor($executor))->flush($this->trackedMap($tracked), $this->records($record));
		} finally {
			self::assertSame([], $executor->getCommands());
			self::assertSame('A2', $record->getValue('name'));
		}
	}

	public function testCommandExecutorExceptionBubbles(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('executor failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('executor failed');

		(new FlushExecutor($executor))->flush(new TrackedRepresentationMap(), $this->records($record));
	}

	public function testNewRecordChangedThroughRepresentationSyncIsInsertedWithSynchronizedValues(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->trackedMap($tracked), $this->records($record));

		$command = $executor->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		self::assertSame(['name' => 'A2'], $command->getValues());
	}

	public function testDirtyRecordChangedThroughRepresentationSyncIsUpdatedAndMarkedCleanByRecordFlusher(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->trackedMap($tracked), $this->records($record));

		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame(['name' => 'A2'], $command->getChanges());
		self::assertTrue($record->isClean());
		self::assertSame(['id' => 10, 'name' => 'A2'], $record->getOriginalValues());
	}

	public function testRemovedRecordsAreHandledByRecordFlusher(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$records = $this->records($record);
		$record->markRemoved();
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush(new TrackedRepresentationMap(), $records);

		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
		self::assertSame([], $records->getAll());
	}

	public function testFlushExecutorDoesNotMentionDatabaseSqlOrTransactionClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Persistence/FlushExecutor.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('EntityManager', $source);
		self::assertStringNotContainsString('UnitOfWork', $source);
		self::assertStringNotContainsString('Database', $source);
		self::assertStringNotContainsString('Sql', $source);
		self::assertStringNotContainsString('SQL', $source);
		self::assertStringNotContainsString('Transaction', $source);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function representation(array $values): stdClass
	{
		$representation = new stdClass();
		foreach ($values as $path => $value) {
			$representation->{$path} = $value;
		}

		return $representation;
	}

	/**
	 * @param array<string, RecordFieldRef> $fields
	 */
	private function binding(array $fields): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		foreach ($fields as $path => $field) {
			$binding->add(new RepresentationFieldBinding($path, $field));
		}

		return $binding;
	}

	private function tracked(object $representation, RepresentationBinding $binding): TrackedRepresentation
	{
		return new TrackedRepresentation($representation, $binding, $this->baselineRevisions($binding));
	}

	private function trackedMap(TrackedRepresentation ...$trackedRepresentations): TrackedRepresentationMap
	{
		$map = new TrackedRepresentationMap();
		foreach ($trackedRepresentations as $tracked) {
			$map->add($tracked);
		}

		return $map;
	}

	/**
	 * @return array<string, int>
	 */
	private function baselineRevisions(RepresentationBinding $binding): array
	{
		$baselineRevisions = [];
		foreach ($binding->getAll() as $fieldBinding) {
			$baselineRevisions[$fieldBinding->getField()->getRecordHash()] = 1;
		}

		return $baselineRevisions;
	}

	private function records(RecordState ...$records): RecordStateMap
	{
		$map = new RecordStateMap();
		foreach ($records as $record) {
			$map->add($record);
		}

		return $map;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}
}
