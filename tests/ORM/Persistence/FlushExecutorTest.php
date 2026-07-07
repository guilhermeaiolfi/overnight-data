<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\State\ValueRef;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;

final class FlushExecutorTest extends TestCase
{
	use OrmFixture;

	/** @var array<int, list<RecordState>> */
	private array $recordsByBindingId = [];

	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testFlushSynchronizesRepresentationChangesBeforePersistencePlanning(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => [$record, 'name'],
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context($this->representations($tracked), $this->records($record)));

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
			'name' => [$record, 'name'],
		]));
		$commandResult = new CommandResult(1, ['id' => 10]);
		$executor = new RecordingCommandExecutor($commandResult);

		$result = (new FlushExecutor($executor))->flush($this->context($this->representations($tracked), $this->records($record)));

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

		(new FlushExecutor($executor))->flush($this->context(new RepresentationStore(), $this->records($new, $dirty, $removed)));

		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[1]);
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[2]);
	}

	public function testSyncExceptionPreventsCommandExecution(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->binding([
			'name' => [$record, 'name'],
		]));
		$record->setValue('name', 'A2');
		$executor = new RecordingCommandExecutor();

		$this->expectException(SyncException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context($this->representations($tracked), $this->records($record)));
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

		(new FlushExecutor($executor))->flush($this->context(new RepresentationStore(), $this->records($record)));
	}

	public function testFailedFlushDoesNotExecuteLaterDependentCommands(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$child = RecordState::new($posts, [
			'title' => 'Post',
			'user_id' => ValueRef::field($owner, 'id'),
		]);
		$executor = new class () implements CommandExecutorInterface {
			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;

				throw new LogicException('owner insert failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('owner insert failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($owner, $child)
			));
		} finally {
			self::assertCount(1, $executor->commands);
			self::assertSame($users, $executor->commands[0]->getCollection());
			self::assertTrue($owner->isNew());
			self::assertTrue($child->isNew());
		}
	}

	public function testGeneratedKeyIsMergedBeforeDependentCommandExecutes(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$child = RecordState::new($posts, [
			'title' => 'Post',
			'user_id' => ValueRef::field($owner, 'id'),
		]);
		$executor = new class () implements CommandExecutorInterface {
			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;

				if (count($this->commands) === 1) {
					return new CommandResult(1, ['id' => 10]);
				}

				if (! $command instanceof InsertCommand) {
					throw new LogicException('Expected child insert.');
				}

				if ($command->getValues()['user_id'] !== 10) {
					throw new LogicException('Dependent command executed before generated key merge.');
				}

				return new CommandResult(1, ['id' => 5]);
			}
		};

		(new FlushExecutor($executor))->flush($this->context(
			new RepresentationStore(),
			$this->records($owner, $child)
		));

		self::assertSame(10, $owner->getValue('id'));
		self::assertCount(2, $executor->commands);
		self::assertFalse($this->commandContainsValueRef($executor->commands[1]));
	}

	public function testManyToManyThroughRowWithUnresolvedValuesFailsBeforeWritingThroughRow(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3, 'label' => 'Tag']);
		$tagRepresentation = $this->representation(['id' => 3, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(new CommandResult(1));

		$this->expectException(InvalidCommandException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context(
				$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
				$this->records($owner, $target),
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertCount(1, $executor->getCommands());
			self::assertSame($users, $executor->getCommands()[0]->getCollection());
			self::assertNotSame($through, $executor->getCommands()[0]->getCollection());
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testFailedFlushLeavesRecordAndRelationStateInspectableWithoutMarkingSuccess(): void
	{
		[$users, $tags] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3, 'label' => 'Tag']);
		$tagRepresentation = $this->representation(['id' => 3, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$records = $this->records($owner, $target);
		$executor = new class () implements CommandExecutorInterface {
			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;

				return match (count($this->commands)) {
					1 => new CommandResult(1, ['id' => 10]),
					default => throw new LogicException('through insert failed'),
				};
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('through insert failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
				$records,
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertSame(10, $owner->getValue('id'));
			self::assertTrue($owner->isNew());
			self::assertSame($owner, $records->getAll()[0]);
			self::assertTrue($collection->hasChanges());
			self::assertCount(2, $executor->commands);
		}
	}

	public function testNonTransactionalRetryCanExplicitlyAdoptGeneratedKeyBeforeRetryingRelationCommands(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3, 'label' => 'Tag']);
		$tagRepresentation = $this->representation(['id' => 3, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$records = $this->records($owner, $target);
		$representations = $this->representations($this->tracked($tagRepresentation, $this->bindingFor($target)));
		$toManyRelations = $this->toManyRelations($collection);
		$firstExecutor = new class () implements CommandExecutorInterface {
			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;

				return match (count($this->commands)) {
					1 => new CommandResult(1, ['id' => 10]),
					default => throw new LogicException('through insert failed'),
				};
			}
		};

		try {
			(new FlushExecutor($firstExecutor))->flush($this->context(
				$representations,
				$records,
				$toManyRelations
			));
			self::fail('Expected first flush to fail.');
		} catch (LogicException $exception) {
			self::assertSame('through insert failed', $exception->getMessage());
		}

		self::assertSame(10, $owner->getValue('id'));
		self::assertTrue($owner->isNew());
		self::assertTrue($collection->hasChanges());

		$owner->markClean($users->getKey(10));
		$records->indexKey($owner);
		$retryExecutor = new RecordingCommandExecutor();

		(new FlushExecutor($retryExecutor))->flush($this->context(
			$representations,
			$records,
			$toManyRelations
		));

		self::assertTrue($owner->isClean());
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $retryExecutor->getCommands());
		$retryCommand = $retryExecutor->getCommands()[0];
		if (! $retryCommand instanceof InsertCommand) {
			self::fail('Expected retry to write only the through insert.');
		}

		self::assertSame($through, $retryCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $retryCommand->getValues());
	}

	public function testTransactionalFailureRestoresGeneratedValuesAndLeavesRelationChangesPending(): void
	{
		[$users, $tags] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3, 'label' => 'Tag']);
		$tagRepresentation = $this->representation(['id' => 3, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$records = $this->records($owner, $target);
		$executor = new class () implements CommandExecutorInterface, TransactionalCommandExecutorInterface {
			public int $transactions = 0;

			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;

				return match (count($this->commands)) {
					1 => new CommandResult(1, ['id' => 10]),
					default => throw new LogicException('through insert failed'),
				};
			}

			public function transaction(callable $callback): mixed
			{
				++$this->transactions;

				return $callback();
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('through insert failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
				$records,
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertSame(1, $executor->transactions);
			self::assertCount(2, $executor->commands);
			self::assertTrue($owner->isNew());
			self::assertFalse($owner->hasValue('id'));
			self::assertNull($owner->getKey());
			self::assertSame(['name' => 'Owner'], $owner->getValues());
			self::assertSame($owner, $records->getAll()[0]);
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testFlushPrefersTransactionalPathWheneverExecutorSupportsTransactions(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$executor = new class () implements CommandExecutorInterface, TransactionalCommandExecutorInterface {
			public int $transactions = 0;

			public bool $insideTransaction = false;

			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				if (! $this->insideTransaction) {
					throw new LogicException('FlushExecutor used the non-transactional path.');
				}

				$this->commands[] = $command;

				return new CommandResult(1);
			}

			public function transaction(callable $callback): mixed
			{
				++$this->transactions;

				$this->insideTransaction = true;

				try {
					return $callback();
				} finally {
					$this->insideTransaction = false;
				}
			}
		};

		(new FlushExecutor($executor))->flush($this->context(new RepresentationStore(), $this->records($record)));

		self::assertSame(1, $executor->transactions);
		self::assertCount(1, $executor->commands);
		self::assertTrue($record->isClean());
	}

	public function testTransactionalFlushRunsSchedulerInsideTransactionWithImmediateValueMergeAndDeferredFinalizers(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::new($tags, ['label' => 'Tag']);
		$tagRepresentation = $this->representation(['label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$records = $this->records($owner, $target);
		$executor = new class () implements CommandExecutorInterface, TransactionalCommandExecutorInterface {
			public bool $insideTransaction = false;

			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				if (! $this->insideTransaction) {
					throw new LogicException('Commands must execute inside the transaction.');
				}

				$this->commands[] = $command;

				return match (count($this->commands)) {
					1 => new CommandResult(1, ['id' => 10]),
					2 => new CommandResult(1, ['id' => 3]),
					default => new CommandResult(1),
				};
			}

			public function transaction(callable $callback): mixed
			{
				$this->insideTransaction = true;

				try {
					return $callback();
				} finally {
					$this->insideTransaction = false;
				}
			}
		};

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$records,
			$this->toManyRelations($collection)
		));

		self::assertCount(3, $executor->commands);
		self::assertSame(10, $owner->getValue('id'));
		self::assertSame(3, $target->getValue('id'));
		self::assertTrue($owner->isClean());
		self::assertTrue($target->isClean());
		self::assertFalse($collection->hasChanges());
		$throughCommand = $executor->commands[2];
		if (! $throughCommand instanceof InsertCommand) {
			self::fail('Expected a through insert command.');
		}

		self::assertSame($through, $throughCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $throughCommand->getValues());
	}

	public function testTransactionalFlushDefersStateCleanupUntilTransactionSucceeds(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$users = $this->usersWithPosts();
		$dirty = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$dirty->setValue('name', 'dirty');
		$removed = RecordState::clean($users->getKey(20), ['id' => 20, 'name' => 'removed']);
		$removed->markRemoved();
		$records = $this->records($dirty, $removed);
		$collection = $this->changedToManyRelationState($dirty);
		$executor = new class () implements CommandExecutorInterface, TransactionalCommandExecutorInterface {
			public int $transactions = 0;

			/** @var list<CommandInterface> */
			public array $commands = [];

			public function execute(CommandInterface $command): CommandResult
			{
				$this->commands[] = $command;
				if ($command instanceof TestCommand) {
					throw new LogicException('relation command failed');
				}

				return new CommandResult(1);
			}

			public function transaction(callable $callback): mixed
			{
				++$this->transactions;

				return $callback();
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('relation command failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$records,
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertSame(1, $executor->transactions);
			self::assertCount(3, $executor->commands);
			self::assertTrue($dirty->isDirty());
			self::assertSame(['id' => 10, 'name' => 'before'], $dirty->getOriginalValues());
			self::assertTrue($removed->isRemoved());
			self::assertSame($removed, $records->getByKey($users->getKey(20)));
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testNewRecordChangedThroughRepresentationSyncIsInsertedWithSynchronizedValues(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => [$record, 'name'],
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context($this->representations($tracked), $this->records($record)));

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
			'name' => [$record, 'name'],
		]));
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context($this->representations($tracked), $this->records($record)));

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

		(new FlushExecutor($executor))->flush($this->context(new RepresentationStore(), $records));

		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
		self::assertSame([], $records->getAll());
	}

	public function testSecondFlushAfterSuccessfulInsertDoesNotInsertAgain(): void
	{
		$record = RecordState::new($this->users(), ['id' => 10, 'name' => 'A1']);
		$records = $this->records($record);
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context(new RepresentationStore(), $records));
		$flusher->flush($this->context(new RepresentationStore(), $records));

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
	}

	public function testSecondFlushAfterSuccessfulUpdateDoesNotUpdateAgain(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$record->setValue('name', 'A2');
		$records = $this->records($record);
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context(new RepresentationStore(), $records));
		$flusher->flush($this->context(new RepresentationStore(), $records));

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
	}

	public function testSecondFlushAfterSuccessfulDeleteDoesNotDeleteAgain(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$record->markRemoved();
		$records = $this->records($record);
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context(new RepresentationStore(), $records));
		$flusher->flush($this->context(new RepresentationStore(), $records));

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
	}

	public function testSecondFlushAfterToManyRelationAddDoesNotRepeatRelationCommand(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(5), ['id' => 5, 'label' => 'Tag']);
		$item = $this->representation(['id' => 5, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$tracked = $this->tracked($item, $this->bindingFor($target));
		$representations = $this->representations($tracked);
		$records = $this->records($owner, $target);
		$toManyRelations = $this->toManyRelations($collection);

		$flusher->flush($this->context($representations, $records, $toManyRelations));
		$flusher->flush($this->context($representations, $records, $toManyRelations));

		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertSame($through, $command->getCollection());
	}

	public function testSecondFlushAfterToManyRelationRemoveDoesNotRepeatRelationCommand(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(5), ['id' => 5, 'label' => 'Tag']);
		$item = $this->representation(['id' => 5, 'label' => 'Tag']);
		$collection = ToManyRelationState::full($owner, 'tags', $this->bindingFor($target), [$item]);
		$collection->remove($item);
		$tracked = $this->tracked($item, $this->bindingFor($target));
		$representations = $this->representations($tracked);
		$records = $this->records($owner, $target);
		$toManyRelations = $this->toManyRelations($collection);
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context($representations, $records, $toManyRelations));
		$flusher->flush($this->context($representations, $records, $toManyRelations));

		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		self::assertInstanceOf(DeleteCommand::class, $command);
		self::assertSame($through, $command->getCollection());
	}

	public function testSecondFlushAfterToOneRelationSetDoesNotRepeatForeignKeyUpdate(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Author']);
		$targetObject = $this->representation(['id' => 10, 'name' => 'Author']);
		$reference = new ToOneRelationState($owner, 'author', $this->bindingFor($target));
		$reference->set($targetObject);
		$tracked = $this->tracked($targetObject, $this->bindingFor($target));
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context($this->representations($tracked), $this->records($owner, $target), new RelationStateStore(), $this->toOneRelations($reference)));
		$flusher->flush($this->context($this->representations($tracked), $this->records($owner, $target), new RelationStateStore(), $this->toOneRelations($reference)));

		self::assertFalse($reference->hasChanges());
		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
	}

	public function testSecondFlushAfterToOneRelationClearDoesNotRepeatForeignKeyNulling(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'author_id' => 10]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Author']);
		$baselineObject = $this->representation(['id' => 10, 'name' => 'Author']);
		$reference = new ToOneRelationState($owner, 'author', $this->bindingFor($target), $baselineObject);
		$reference->clear();
		$tracked = $this->tracked($baselineObject, $this->bindingFor($target));
		$executor = new RecordingCommandExecutor();
		$flusher = new FlushExecutor($executor);

		$flusher->flush($this->context($this->representations($tracked), $this->records($owner, $target), new RelationStateStore(), $this->toOneRelations($reference)));
		$flusher->flush($this->context($this->representations($tracked), $this->records($owner, $target), new RelationStateStore(), $this->toOneRelations($reference)));

		self::assertFalse($reference->hasChanges());
		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
	}

	public function testRepresentationSyncRunsBeforeRelationPersistencePlanning(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$tracked = $this->tracked($this->representation(['name' => 'after']), $this->binding([
			'name' => [$record, 'name'],
		]));
		$toManyRelations = $this->toManyRelations($this->changedToManyRelationState($record));

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context($this->representations($tracked), $this->records($record), $toManyRelations));

		self::assertSame(['after'], RecordingRelationPersistencePlanner::$observedOwnerValues);
	}

	public function testRelationRepresentationSyncRunsAfterScalarRepresentationSyncAndBeforeRelationPersistencePlanning(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$item = new stdClass();
		$tracked = $this->tracked($this->representation(['name' => 'after', 'posts' => [$item]]), $this->ownerBindingWithPosts($record));

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context($this->representations($tracked, $this->tracked($item, new RepresentationBinding($this->posts()))), $this->records($record), new RelationStateStore()));

		self::assertSame(['after'], RecordingRelationPersistencePlanner::$observedOwnerValues);
		self::assertCount(1, RecordingRelationPersistencePlanner::$collections);
		self::assertSame([$item], RecordingRelationPersistencePlanner::$collections[0]->getItems());
	}

	public function testRelationRepresentationSyncCanCreateToManyRelationStateThatIsPlannedByRelationPersistencePlanner(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$item = new stdClass();
		$toManyRelations = new RelationStateStore();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context(
			$this->representations(
				$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerBindingWithPosts($record)),
				$this->tracked($item, new RepresentationBinding($this->posts()))
			),
			$this->records($record),
			$toManyRelations
		));

		$collection = $toManyRelations->get($record, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
		self::assertSame([$collection], RecordingRelationPersistencePlanner::$collections);
		self::assertFalse($collection->hasChanges());
	}

	public function testReferencesArePassedThroughRelationRepresentationSyncAndRelationPersistencePlanning(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = new stdClass();
		$toOneRelations = new RelationStateStore();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context(
			$this->representations(
				$this->tracked($this->representation(['name' => 'Owner', 'profile' => $target]), $this->ownerBindingWithProfile($record)),
				$this->tracked($target, new RepresentationBinding($this->profiles()))
			),
			$this->records($record),
			new RelationStateStore(),
			$toOneRelations
		));

		$reference = $toOneRelations->get($record, 'profile');
		self::assertInstanceOf(ToOneRelationState::class, $reference);
		self::assertSame($target, $reference->getTarget());
		self::assertSame([$reference], RecordingRelationPersistencePlanner::$changes);
		self::assertFalse($reference->hasChanges());
	}

	public function testRelationRepresentationSyncExceptionPreventsScalarRecordFlush(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$executor = new RecordingCommandExecutor();

		$this->expectException(SyncException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context(
				$this->representations($this->tracked($this->representation(['name' => 'after', 'posts' => 'bad']), $this->ownerBindingWithPosts($record))),
				$this->records($record),
				new RelationStateStore()
			));
		} finally {
			self::assertSame([], $executor->getCommands());
			self::assertSame(0, RecordingRelationPersistencePlanner::$calls);
		}
	}

	public function testRelationChangesInferredByRelationRepresentationSyncAreClearedOnlyAfterSuccessfulFlush(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$item = new stdClass();
		$toManyRelations = new RelationStateStore();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context(
			$this->representations(
				$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerBindingWithPosts($record)),
				$this->tracked($item, new RepresentationBinding($this->posts()))
			),
			$this->records($record),
			$toManyRelations
		));

		$collection = $toManyRelations->get($record, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
		self::assertFalse($collection->hasChanges());
	}

	public function testPlannedToOneRelationStateChangesAreClearedOnlyAfterSuccessfulFlush(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$reference = $this->changedToOneRelationState($record);

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context(
			new RepresentationStore(),
			$this->records($record),
			new RelationStateStore(),
			$this->toOneRelations($reference)
		));

		self::assertFalse($reference->hasChanges());
	}

	public function testRelationPersistencePlanningRunsBeforeRecordFlusher(): void
	{
		RecordingRelationPersistencePlanner::$mutateOwnerField = 'name';
		RecordingRelationPersistencePlanner::$mutateOwnerValue = 'planned';
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context(
			new RepresentationStore(),
			$this->records($record),
			$this->toManyRelations($this->changedToManyRelationState($record))
		));

		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame(['name' => 'planned'], $command->getChanges());
	}

	public function testRelationProducedCommandsExecuteAfterScalarRecordCommandsAndResultsKeepOrder(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$record->setValue('name', 'dirty');
		$scalarResult = new CommandResult(1);
		$relationResult = new CommandResult(2);
		$executor = new RecordingCommandExecutor(results: [$scalarResult, $relationResult]);

		$result = (new FlushExecutor($executor))->flush($this->context(
			new RepresentationStore(),
			$this->records($record),
			$this->toManyRelations($this->changedToManyRelationState($record))
		));

		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(TestCommand::class, $executor->getCommands()[1]);
		self::assertSame([$scalarResult, $relationResult], $result->getCommandResults());
	}

	public function testRelationCollectionsClearChangesOnlyAfterAllCommandsSucceed(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedToManyRelationState($record);

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->context(
			new RepresentationStore(),
			$this->records($record),
			$this->toManyRelations($collection)
		));

		self::assertFalse($collection->hasChanges());
	}

	public function testRelationChangesAreNotClearedIfRelationPlanningThrows(): void
	{
		$users = $this->usersWithPosts(false);
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedToManyRelationState($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(RelationPersistenceException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertTrue($collection->hasChanges());
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationRepresentationSyncThrows(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedToOneRelationState($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(SyncException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context(
				$this->representations($this->tracked($this->representation(['name' => 'after', 'profile' => 'bad']), $this->ownerBindingWithProfile($record))),
				$this->records($record),
				new RelationStateStore(),
				$this->toOneRelations($reference)
			));
		} finally {
			self::assertTrue($reference->hasChanges());
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationPlanningThrows(): void
	{
		$users = $this->usersWithProfile(false);
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedToOneRelationState($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(RelationPersistenceException::class);

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				new RelationStateStore(),
				$this->toOneRelations($reference)
			));
		} finally {
			self::assertTrue($reference->hasChanges());
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testHasManyAddFlushMutatesChildForeignKeyBeforeScalarRecordFlush(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$item = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$collection = new ToManyRelationState($owner, 'posts', $this->bindingFor($child));
		$collection->add($item);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($item, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->toManyRelations($collection)
		));

		self::assertSame(10, $child->getValue('user_id'));
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['user_id' => 10], $command->getChanges());
	}

	public function testHasManyAddWithGeneratedOwnerKeyFlushesOwnerThenChildWithConcreteForeignKey(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$child = RecordState::new($posts, ['title' => 'Post']);
		$postRepresentation = $this->representation(['title' => 'Post']);
		$collection = new ToManyRelationState($owner, 'posts', $this->bindingFor($child));
		$collection->add($postRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1, ['id' => 5]),
		]);

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($postRepresentation, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->toManyRelations($collection)
		));

		self::assertFalse($collection->hasChanges());
		self::assertSame(10, $owner->getValue('id'));
		self::assertSame(10, $child->getValue('user_id'));
		self::assertCount(2, $executor->getCommands());
		$ownerCommand = $executor->getCommands()[0];
		$childCommand = $executor->getCommands()[1];
		self::assertInstanceOf(InsertCommand::class, $ownerCommand);
		self::assertInstanceOf(InsertCommand::class, $childCommand);
		self::assertSame($users, $ownerCommand->getCollection());
		self::assertSame($posts, $childCommand->getCollection());
		self::assertSame(['name' => 'Owner'], $ownerCommand->getValues());
		self::assertSame(['title' => 'Post', 'user_id' => 10], $childCommand->getValues());
		self::assertNotContains('ref', array_map(
			static fn (mixed $value): string => $value instanceof ValueRef ? 'ref' : 'scalar',
			array_merge($ownerCommand->getValues(), $childCommand->getValues()),
		));
	}

	public function testManyToManyAddWithGeneratedOwnerAndTargetKeysFlushesThroughCommandWithConcreteValues(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::new($tags, ['label' => 'Tag']);
		$tagRepresentation = $this->representation(['label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1, ['id' => 3]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->toManyRelations($collection)
		));

		self::assertSame(10, $owner->getValue('id'));
		self::assertSame(3, $target->getValue('id'));
		self::assertFalse($collection->hasChanges());
		self::assertCount(3, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[1]);
		$throughCommand = $executor->getCommands()[2];
		if (! $throughCommand instanceof InsertCommand) {
			self::fail('Expected a through insert command.');
		}

		self::assertSame($through, $throughCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $throughCommand->getValues());
		self::assertFalse($this->commandContainsValueRef($throughCommand));
	}

	public function testManyToManyAddWithConcreteOwnerAndGeneratedTargetFlushesThroughCommandWithConcreteValues(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = RecordState::new($tags, ['label' => 'Tag']);
		$tagRepresentation = $this->representation(['label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 3]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->toManyRelations($collection)
		));

		self::assertCount(2, $executor->getCommands());
		$throughCommand = $executor->getCommands()[1];
		if (! $throughCommand instanceof InsertCommand) {
			self::fail('Expected a through insert command.');
		}

		self::assertSame($through, $throughCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $throughCommand->getValues());
		self::assertFalse($this->commandContainsValueRef($throughCommand));
	}

	public function testManyToManyAddWithGeneratedOwnerAndConcreteTargetFlushesThroughCommandWithConcreteValues(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3, 'label' => 'Tag']);
		$tagRepresentation = $this->representation(['id' => 3, 'label' => 'Tag']);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->toManyRelations($collection)
		));

		self::assertCount(2, $executor->getCommands());
		$throughCommand = $executor->getCommands()[1];
		if (! $throughCommand instanceof InsertCommand) {
			self::fail('Expected a through insert command.');
		}

		self::assertSame($through, $throughCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $throughCommand->getValues());
		self::assertFalse($this->commandContainsValueRef($throughCommand));
	}

	public function testNullableHasManyRemoveFlushSetsChildForeignKeyToNullThroughScalarUpdate(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts(nullable: true);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'user_id' => 10]);
		$item = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => 10]);
		$collection = ToManyRelationState::full($owner, 'posts', $this->bindingFor($child), [$item]);
		$collection->remove($item);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations($this->tracked($item, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->toManyRelations($collection)
		));

		self::assertNull($child->getValue('user_id'));
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['user_id' => null], $command->getChanges());
	}

	public function testHasOneSetFlushMutatesTargetForeignKeyThroughScalarUpdate(): void
	{
		[$users, $profiles] = $this->usersWithDefaultHasOneProfile();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$profileRepresentation = $this->representation(['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'profile' => $profileRepresentation]);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush($this->context(
			$this->representations(
				$this->tracked($profileRepresentation, $this->bindingFor($target)),
				$this->tracked($ownerRepresentation, $this->ownerBindingWithProfile($owner))
			),
			$this->records($owner, $target),
			new RelationStateStore(),
			new RelationStateStore()
		));

		self::assertSame(10, $target->getValue('user_id'));
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($profiles, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['user_id' => 10], $command->getChanges());
	}

	public function testRelationChangesAreNotClearedIfScalarRecordFlushThrows(): void
	{
		$record = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'before']);
		$record->setValue('name', 'dirty');
		$collection = $this->changedToManyRelationState($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('scalar failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('scalar failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testReferenceChangesAreNotClearedIfScalarRecordFlushThrows(): void
	{
		$record = RecordState::clean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'before']);
		$record->setValue('name', 'dirty');
		$reference = $this->changedToOneRelationState($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('scalar failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('scalar failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				new RelationStateStore(),
				$this->toOneRelations($reference)
			));
		} finally {
			self::assertTrue($reference->hasChanges());
		}
	}

	public function testRelationChangesAreNotClearedIfRelationCommandExecutionThrows(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedToManyRelationState($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('relation command failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('relation command failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				$this->toManyRelations($collection)
			));
		} finally {
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationCommandExecutionThrows(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedToOneRelationState($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('relation command failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('relation command failed');

		try {
			(new FlushExecutor($executor))->flush($this->context(
				new RepresentationStore(),
				$this->records($record),
				new RelationStateStore(),
				$this->toOneRelations($reference)
			));
		} finally {
			self::assertTrue($reference->hasChanges());
		}
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
	}

	/**
	 * @param array<string, array{RecordState, string}> $fields
	 */
	private function binding(array $fields): RepresentationBinding
	{
		$first = reset($fields);
		$binding = new RepresentationBinding($first[0]->getCollection());
		$records = [];
		foreach ($fields as $path => [$record, $fieldName]) {
			$binding->addField(new RepresentationFieldBinding((string) $path, $record->getCollection(), $fieldName));
			$records[$record->getStateHash()] = $record;
		}
		$this->recordsByBindingId[spl_object_id($binding)] = array_values($records);

		return $binding;
	}

	private function tracked(object $representation, RepresentationBinding $binding): RepresentationState
	{
		$records = $this->recordsByBindingId[spl_object_id($binding)] ?? [];
		$fieldItems = [];
		foreach ($binding->getFields() as $fieldBinding) {
			foreach ($records as $record) {
				if ($record->getCollection()->getName() !== $fieldBinding->getCollectionName()) {
					continue;
				}

				$fieldItems[] = new RepresentationFieldStateItem($fieldBinding, $record, $fieldBinding->getFieldName(), $record->getRevision());
				break;
			}
		}

		$relationItems = [];
		foreach ($binding->getRelations() as $relationBinding) {
			foreach ($records as $record) {
				if ($record->getCollection()->getName() !== $relationBinding->getOwnerCollectionName()) {
					continue;
				}

				$relationItems[] = new RepresentationRelationStateItem($relationBinding, $record, $relationBinding->getRelationName());
				break;
			}
		}

		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding, $fieldItems, $relationItems)
		);
	}


	private function toManyRelations(ToManyRelationState ...$collections): RelationStateStore
	{
		$map = new RelationStateStore();
		foreach ($collections as $collection) {
			$map->add($collection);
		}

		return $map;
	}

	private function toOneRelations(ToOneRelationState ...$references): RelationStateStore
	{
		$map = new RelationStateStore();
		foreach ($references as $reference) {
			$map->add($reference);
		}

		return $map;
	}

	private function changedToManyRelationState(RecordState $owner): ToManyRelationState
	{
		$collection = new ToManyRelationState($owner, 'posts', $this->postBinding());
		$collection->add(new stdClass());

		return $collection;
	}

	private function changedToOneRelationState(RecordState $owner): ToOneRelationState
	{
		$reference = new ToOneRelationState($owner, 'profile', $this->postBinding());
		$reference->set(new stdClass());

		return $reference;
	}

	private function ownerBindingWithPosts(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding($record->getCollection());
		$binding->addField(new RepresentationFieldBinding('name', $record->getCollection(), 'name'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$record->getCollection(), 'posts',
			$this->postBinding()
		));
		$this->recordsByBindingId[spl_object_id($binding)] = [$record];

		return $binding;
	}

	private function ownerBindingWithProfile(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding($record->getCollection());
		$binding->addField(new RepresentationFieldBinding('name', $record->getCollection(), 'name'));
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			$record->getCollection(), 'profile',
			$this->postBinding()
		));
		$this->recordsByBindingId[spl_object_id($binding)] = [$record];

		return $binding;
	}

	private function usersWithPosts(bool $withPlanner = true): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$relation = $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('id');
		if ($withPlanner) {
			$relation->persistencePlanner(RecordingRelationPersistencePlanner::class);
		}

		return $users;
	}

	private function usersWithProfile(bool $withPlanner = true): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('profiles')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$relation = $users->hasOne('profile', 'profiles')
			->innerKey('id')
			->outerKey('user_id');
		if ($withPlanner) {
			$relation->persistencePlanner(RecordingRelationPersistencePlanner::class);
		}

		return $users;
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithDefaultHasManyPosts(bool $nullable = false): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id')->nullable($nullable);

		return [$users, $posts];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithDefaultHasOneProfile(): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->field('user_id', 'int')->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');

		return [$users, $profiles];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function postsWithDefaultBelongsToAuthor(): array
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('author_id', 'int')->end();
		$posts->belongsTo('author', 'users')->innerKey('author_id')->outerKey('id')->nullable(true);

		return [$posts, $users];
	}

	private function bindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding($record->getCollection());
		foreach (array_keys($record->getValues()) as $field) {
			$field = (string) $field;
			$binding->addField(new RepresentationFieldBinding($field, $record->getCollection(), $field));
		}
		$this->recordsByBindingId[spl_object_id($binding)] = [$record];

		return $binding;
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function usersWithTags(): array
	{
		$registry = new Registry();
		$registry->collection('tags')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->end();
		$registry->collection('user_tag')
			->field('user_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('user_tag')
				->innerKey('user_id')
				->outerKey('tag_id')
				->end();
		$tags = $registry->getCollection('tags');
		$through = $registry->getCollection('user_tag');
		self::assertInstanceOf(CollectionInterface::class, $tags);
		self::assertInstanceOf(CollectionInterface::class, $through);

		return [$users, $tags, $through];
	}

	private function commandContainsValueRef(CommandInterface $command): bool
	{
		if ($command instanceof InsertCommand) {
			return $this->containsValueRef($command->getValues());
		}

		if ($command instanceof UpdateCommand) {
			return $this->containsValueRef($command->getIdentity())
				|| $this->containsValueRef($command->getChanges());
		}

		if ($command instanceof DeleteCommand) {
			return $this->containsValueRef($command->getIdentity());
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function containsValueRef(array $values): bool
	{
		foreach ($values as $value) {
			if ($value instanceof ValueRef) {
				return true;
			}
		}

		return false;
	}
}
