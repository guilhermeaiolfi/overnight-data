<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\ExpectedAffectedRows;
use ON\Data\ORM\Persistence\FlushScheduler;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class FlushSchedulerTest extends TestCase
{
	use OrmFixture;

	public function testCleanRecordsAreSkippedAndNoCommandIsExecuted(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		$results = (new FlushScheduler($executor))->run($states)->getCommandResults();

		self::assertSame([], $results);
		self::assertSame([], $executor->getCommands());
		self::assertTrue($record->isClean());
	}

	public function testNewRecordExecutesInsertCommandAndBecomesClean(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor();

		(new FlushScheduler($executor))->run($states);

		self::assertCount(1, $executor->getCommands());
		self::assertContainsOnlyInstancesOf(InsertCommand::class, $executor->getCommands());
		self::assertTrue($record->isClean());
		self::assertNull($record->getKey());
	}

	public function testNewRecordWithApplicationAssignedPrimaryKeyIsIndexedAfterFlush(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);
		$states = $this->states($record);

		(new FlushScheduler(new RecordingCommandExecutor()))->run($states);

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

		(new FlushScheduler($executor))->run($states);

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

		(new FlushScheduler($executor))->run($states);

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

		(new FlushScheduler($executor))->run($states);

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

		$results = (new FlushScheduler($executor))->run($states)->getCommandResults();

		self::assertSame([], $results);
		self::assertSame([], $executor->getCommands());
		self::assertSame([], $states->getAll());
	}

	public function testRunReturnsCommandResultsInExecutionOrder(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['name' => 'Ada']);
		$second = RecordState::clean($users->getKey(20), ['id' => 20, 'name' => 'Grace']);
		$second->setValue('name', 'Katherine');
		$firstResult = new CommandResult(1, ['id' => 10]);
		$secondResult = new CommandResult(1);
		$executor = new RecordingCommandExecutor(results: [$firstResult, $secondResult]);

		$results = (new FlushScheduler($executor))->run($this->states($first, $second))->getCommandResults();

		self::assertSame([$firstResult, $secondResult], $results);
	}

	public function testMultipleStatesAreFlushedInRecordStateStoreInsertionOrder(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);
		$second = RecordState::new($users, ['id' => 20, 'name' => 'Grace']);
		$executor = new RecordingCommandExecutor();

		(new FlushScheduler($executor))->run($this->states($first, $second));

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

	public function testInsertWithZeroAffectedRowsDoesNotMarkOrIndexRecordClean(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);
		$states = $this->states($record);
		$key = $users->getKey(10);
		$executor = new RecordingCommandExecutor(new CommandResult(0));

		$this->expectException(UnexpectedAffectedRowsException::class);

		try {
			(new FlushScheduler($executor))->run($states);
		} finally {
			self::assertTrue($record->isNew());
			self::assertSame(['id' => 10, 'name' => 'Ada'], $record->getValues());
			self::assertNull($states->getByKey($key));
		}
	}

	public function testUpdateWithZeroAffectedRowsDoesNotMarkRecordClean(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->setValue('name', 'Grace');
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor(new CommandResult(0));

		$this->expectException(UnexpectedAffectedRowsException::class);

		try {
			(new FlushScheduler($executor))->run($states);
		} finally {
			self::assertTrue($record->isDirty());
			self::assertSame('Grace', $record->getValue('name'));
			self::assertSame($record, $states->getByKey($users->getKey(10)));
		}
	}

	public function testDeleteWithZeroAffectedRowsDoesNotRemoveRecordState(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$record->markRemoved();
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor(new CommandResult(0));

		$this->expectException(UnexpectedAffectedRowsException::class);

		try {
			(new FlushScheduler($executor))->run($states);
		} finally {
			self::assertTrue($record->isRemoved());
			self::assertSame($record, $states->getByKey($users->getKey(10)));
		}
	}

	public function testBufferedRelationCommandWithZeroAffectedRowsThrows(): void
	{
		$through = $this->throughCollection();
		$command = new InsertCommand($through, ['user_id' => 10, 'tag_id' => 3]);
		$executor = new RecordingCommandExecutor(new CommandResult(0));

		$this->expectException(UnexpectedAffectedRowsException::class);
		$this->expectExceptionMessage("Insert command for collection 'user_tag' expected to affect 1 row, affected 0.");

		(new FlushScheduler($executor))->run($this->states(), [$command]);
	}

	public function testBufferedThroughDeleteWithZeroAffectedRowsPasses(): void
	{
		$through = $this->throughCollection();
		$command = new DeleteCommand(
			$through,
			['user_id' => 10, 'tag_id' => 3],
			ExpectedAffectedRows::zeroOrOne(),
		);
		$executor = new RecordingCommandExecutor(new CommandResult(0));

		$results = (new FlushScheduler($executor))->run($this->states(), [$command])->getCommandResults();

		self::assertCount(1, $results);
		self::assertSame(0, $results[0]->getAffectedRows());
		self::assertSame([$command], $executor->getCommands());
	}

	public function testBufferedThroughDeleteWithTwoAffectedRowsThrows(): void
	{
		$through = $this->throughCollection();
		$command = new DeleteCommand(
			$through,
			['user_id' => 10, 'tag_id' => 3],
			ExpectedAffectedRows::zeroOrOne(),
		);
		$executor = new RecordingCommandExecutor(new CommandResult(2));

		$this->expectException(UnexpectedAffectedRowsException::class);
		$this->expectExceptionMessage("Delete command for collection 'user_tag' with identity {\"user_id\":10,\"tag_id\":3} expected to affect 0 or 1 rows, affected 2.");

		(new FlushScheduler($executor))->run($this->states(), [$command]);
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
			(new FlushScheduler($executor))->run($states);
		} finally {
			self::assertTrue($record->isNew());
			self::assertNull($record->getKey());
			self::assertSame($record, $states->getAll()[0] ?? null);
		}
	}

	public function testRunUsesNeutralCommandsWithoutSqlSpecificBehavior(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$executor = new RecordingCommandExecutor();

		(new FlushScheduler($executor))->run($this->states($record));

		self::assertContainsOnlyInstancesOf(CommandInterface::class, $executor->getCommands());
		self::assertFalse(method_exists($executor->getCommands()[0], 'getSql'));
	}

	public function testNewParentWithGeneratedIdAndNewChildFlushesInDependencyWaves(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$parent = RecordState::new($users, ['name' => 'Ada']);
		$child = RecordState::new($posts, ['title' => 'Draft']);
		$child->setValue('user_id', $parent->getValueRef('id'));
		$firstResult = new CommandResult(1, ['id' => 10]);
		$secondResult = new CommandResult(1, ['id' => 50]);
		$executor = new RecordingCommandExecutor(results: [$firstResult, $secondResult]);

		$results = (new FlushScheduler($executor))->run($this->states($parent, $child))->getCommandResults();

		self::assertSame([$firstResult, $secondResult], $results);
		self::assertSame(['name' => 'Ada', 'id' => 10], $parent->getValues());
		self::assertSame(['title' => 'Draft', 'user_id' => 10, 'id' => 50], $child->getValues());
		self::assertCount(2, $executor->getCommands());
		$parentCommand = $executor->getCommands()[0];
		$childCommand = $executor->getCommands()[1];
		self::assertInstanceOf(InsertCommand::class, $parentCommand);
		self::assertInstanceOf(InsertCommand::class, $childCommand);
		self::assertSame(['name' => 'Ada'], $parentCommand->getValues());
		self::assertSame(['title' => 'Draft', 'user_id' => 10], $childCommand->getValues());
	}

	public function testDirtyRecordWithUnresolvedValueRefWaitsUntilSourceRecordIsInserted(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$parent = RecordState::new($users, ['name' => 'Ada']);
		$child = RecordState::clean($posts->getKey(50), ['id' => 50, 'title' => 'Draft', 'user_id' => null]);
		$child->setValue('user_id', $parent->getValueRef('id'));
		$insertResult = new CommandResult(1, ['id' => 10]);
		$updateResult = new CommandResult(1);
		$executor = new RecordingCommandExecutor(results: [$insertResult, $updateResult]);

		$results = (new FlushScheduler($executor))->run($this->states($child, $parent))->getCommandResults();

		self::assertSame([$insertResult, $updateResult], $results);
		self::assertCount(2, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		$update = $executor->getCommands()[1];
		self::assertInstanceOf(UpdateCommand::class, $update);
		self::assertSame(['id' => 50], $update->getIdentity());
		self::assertSame(['user_id' => 10], $update->getChanges());
	}

	public function testCompositeGeneratedDependencyResolvesAllKeyRefsBeforeInsert(): void
	{
		[$owners, $children] = $this->compositeOwnerChildCollections();
		$owner = RecordState::new($owners, ['name' => 'Ada']);
		$child = RecordState::new($children, ['label' => 'Profile']);
		$child->setValue('tenant_ref', $owner->getValueRef('tenant_id'));
		$child->setValue('user_ref', $owner->getValueRef('user_id'));
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['tenant_id' => 7, 'user_id' => 10]),
			new CommandResult(1, ['id' => 5]),
		]);

		(new FlushScheduler($executor))->run($this->states($owner, $child));

		$childCommand = $executor->getCommands()[1];
		self::assertInstanceOf(InsertCommand::class, $childCommand);
		self::assertSame(['label' => 'Profile', 'tenant_ref' => 7, 'user_ref' => 10], $childCommand->getValues());
	}

	public function testUnresolvedValueRefWithNoProgressThrowsClearException(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$parent = RecordState::new($users, ['name' => 'Ada']);
		$child = RecordState::new($posts, ['title' => 'Draft']);
		$child->setValue('user_id', $parent->getValueRef('id'));
		$executor = new RecordingCommandExecutor(new CommandResult(1));

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage("collection 'posts'");
		$this->expectExceptionMessage("field 'user_id'");
		$this->expectExceptionMessage('users.id');

		(new FlushScheduler($executor))->run($this->states($parent, $child));
	}

	public function testRemovedRecordsIgnoreUnresolvedNonIdentityValues(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$parent = RecordState::new($users, ['name' => 'Ada']);
		$child = RecordState::clean($posts->getKey(50), ['id' => 50, 'title' => 'Draft', 'user_id' => null]);
		$child->setValue('user_id', $parent->getValueRef('id'));
		$child->markRemoved();
		$executor = new RecordingCommandExecutor();
		$states = $this->states($child, $parent);

		(new FlushScheduler($executor))->run($states);

		self::assertCount(2, $executor->getCommands());
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[1]);
		self::assertSame(['id' => 50], $executor->getCommands()[0]->getIdentity());
	}

	public function testManyToManyWithNewOwnerAndTargetProducesOrderedCommandResultStream(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::new($tags, ['label' => 'Tag']);
		$throughCommand = new InsertCommand($through, [
			'user_id' => $owner->getValueRef('id'),
			'tag_id' => $target->getValueRef('id'),
		]);
		$ownerResult = new CommandResult(1, ['id' => 10]);
		$targetResult = new CommandResult(1, ['id' => 3]);
		$throughResult = new CommandResult(1);
		$executor = new RecordingCommandExecutor(results: [$ownerResult, $targetResult, $throughResult]);

		$results = (new FlushScheduler($executor))->run(
			$this->states($owner, $target),
			[$throughCommand],
		)->getCommandResults();

		self::assertSame([$ownerResult, $targetResult, $throughResult], $results);
		self::assertCount(3, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[1]);
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[2]);
		self::assertSame($users, $executor->getCommands()[0]->getCollection());
		self::assertSame($tags, $executor->getCommands()[1]->getCollection());
		self::assertSame($through, $executor->getCommands()[2]->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $executor->getCommands()[2]->getValues());
	}

	public function testExplicitCommandWithUnresolvedValueRefIsNotExecutedWhileUnresolved(): void
	{
		$users = $this->users();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$through = $this->throughCollection();
		$throughCommand = new InsertCommand($through, [
			'user_id' => $owner->getValueRef('id'),
			'tag_id' => 5,
		]);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1),
		]);

		(new FlushScheduler($executor))->run($this->states($owner), [$throughCommand]);

		self::assertCount(2, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
		self::assertSame($through, $executor->getCommands()[1]->getCollection());
	}

	public function testBlockedExplicitCommandPreventsLaterExplicitCommandsFromExecuting(): void
	{
		$users = $this->users();
		$unresolvableOwner = RecordState::new($users, ['name' => 'Owner']);
		$through = $this->throughCollection();
		$blockedCommand = new InsertCommand($through, [
			'user_id' => $unresolvableOwner->getValueRef('id'),
			'tag_id' => 5,
		]);
		$readyCommand = new InsertCommand($through, [
			'user_id' => 10,
			'tag_id' => 6,
		]);
		$executor = new RecordingCommandExecutor();

		$this->expectException(InvalidCommandException::class);

		try {
			(new FlushScheduler($executor))->run(new RecordStateStore(), [$blockedCommand, $readyCommand]);
		} finally {
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testUnresolvedExplicitCommandReusesCommandValueResolverErrorMessage(): void
	{
		$users = $this->users();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$through = $this->throughCollection();
		$throughCommand = new InsertCommand($through, [
			'user_id' => $owner->getValueRef('id'),
			'tag_id' => 5,
		]);
		$executor = new RecordingCommandExecutor();

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Insert command');
		$this->expectExceptionMessage('values');
		$this->expectExceptionMessage("field 'user_id'");
		$this->expectExceptionMessage('users.id');

		(new FlushScheduler($executor))->run($this->states($owner), [$throughCommand]);
	}

	public function testDeferredFinalizersRunOnlyWhenFinalizeIsCalled(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, ['name' => 'Ada']);
		$states = $this->states($record);
		$executor = new RecordingCommandExecutor(new CommandResult(1, ['id' => 10]));

		$flush = (new FlushScheduler($executor))->run($states, [], true);

		self::assertTrue($record->isNew());
		self::assertNull($record->getKey());
		$flush->finalize();
		self::assertTrue($record->isClean());
		self::assertTrue($record->hasKey());
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function compositeOwnerChildCollections(): array
	{
		$registry = new Registry();
		$children = $registry->collection('children')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end();
		$owners = $registry->collection('owners')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('name', 'string')->end();

		return [$owners, $children];
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

	private function throughCollection(): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('user_tag')
			->field('user_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();

		return $registry->getCollection('user_tag');
	}

	private function states(RecordState ...$records): RecordStateStore
	{
		$states = new RecordStateStore();
		foreach ($records as $record) {
			$states->add($record);
		}

		return $states;
	}
}
