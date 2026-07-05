<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\State\ValueRef;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class FlushExecutorTest extends TestCase
{
	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

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

	public function testRepresentationSyncRunsBeforeRelationPersistencePlanning(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$tracked = $this->tracked($this->representation(['name' => 'after']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$relations = $this->relations($this->changedRelatedCollection($record));

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->trackedMap($tracked), $this->records($record), $relations);

		self::assertSame(['after'], RecordingRelationPersistencePlanner::$observedOwnerValues);
	}

	public function testRelationRepresentationSyncRunsAfterScalarRepresentationSyncAndBeforeRelationPersistencePlanning(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$item = new stdClass();
		$tracked = $this->tracked($this->representation(['name' => 'after', 'posts' => [$item]]), $this->ownerBindingWithPosts($record));

		(new FlushExecutor(new RecordingCommandExecutor()))->flush($this->trackedMap($tracked, $this->tracked($item, new RepresentationBinding())), $this->records($record), new RelatedCollectionMap());

		self::assertSame(['after'], RecordingRelationPersistencePlanner::$observedOwnerValues);
		self::assertCount(1, RecordingRelationPersistencePlanner::$collections);
		self::assertSame([$item], RecordingRelationPersistencePlanner::$collections[0]->getAdded());
	}

	public function testRelationRepresentationSyncCanCreateRelatedCollectionThatIsPlannedByRelationPersistencePlanner(): void
	{
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$item = new stdClass();
		$relations = new RelatedCollectionMap();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerBindingWithPosts($record)),
				$this->tracked($item, new RepresentationBinding())
			),
			$this->records($record),
			$relations
		);

		$collection = $relations->get($record, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertSame([$collection], RecordingRelationPersistencePlanner::$collections);
		self::assertFalse($collection->hasChanges());
	}

	public function testReferencesArePassedThroughRelationRepresentationSyncAndRelationPersistencePlanning(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = new stdClass();
		$references = new RelatedReferenceMap();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Owner', 'profile' => $target]), $this->ownerBindingWithProfile($record)),
				$this->tracked($target, new RepresentationBinding())
			),
			$this->records($record),
			new RelatedCollectionMap(),
			$references
		);

		$reference = $references->get($record, 'profile');
		self::assertInstanceOf(RelatedReference::class, $reference);
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
			(new FlushExecutor($executor))->flush(
				$this->trackedMap($this->tracked($this->representation(['name' => 'after', 'posts' => 'bad']), $this->ownerBindingWithPosts($record))),
				$this->records($record),
				new RelatedCollectionMap()
			);
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
		$relations = new RelatedCollectionMap();

		(new FlushExecutor(new RecordingCommandExecutor()))->flush(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerBindingWithPosts($record)),
				$this->tracked($item, new RepresentationBinding())
			),
			$this->records($record),
			$relations
		);

		$collection = $relations->get($record, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertFalse($collection->hasChanges());
	}

	public function testPlannedRelatedReferenceChangesAreClearedOnlyAfterSuccessfulFlush(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$reference = $this->changedRelatedReference($record);

		(new FlushExecutor(new RecordingCommandExecutor()))->flush(
			new TrackedRepresentationMap(),
			$this->records($record),
			new RelatedCollectionMap(),
			$this->references($reference)
		);

		self::assertFalse($reference->hasChanges());
	}

	public function testRelationPersistencePlanningRunsBeforeRecordFlusher(): void
	{
		RecordingRelationPersistencePlanner::$mutateOwnerField = 'name';
		RecordingRelationPersistencePlanner::$mutateOwnerValue = 'planned';
		$users = $this->usersWithPosts();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush(
			new TrackedRepresentationMap(),
			$this->records($record),
			$this->relations($this->changedRelatedCollection($record))
		);

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

		$result = (new FlushExecutor($executor))->flush(
			new TrackedRepresentationMap(),
			$this->records($record),
			$this->relations($this->changedRelatedCollection($record))
		);

		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
		self::assertInstanceOf(TestCommand::class, $executor->getCommands()[1]);
		self::assertSame([$scalarResult, $relationResult], $result->getCommandResults());
	}

	public function testRelationCollectionsClearChangesOnlyAfterAllCommandsSucceed(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedRelatedCollection($record);

		(new FlushExecutor(new RecordingCommandExecutor()))->flush(
			new TrackedRepresentationMap(),
			$this->records($record),
			$this->relations($collection)
		);

		self::assertFalse($collection->hasChanges());
	}

	public function testRelationChangesAreNotClearedIfRelationPlanningThrows(): void
	{
		$users = $this->usersWithPosts(false);
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedRelatedCollection($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(\ON\Data\ORM\Exception\RelationPersistenceException::class);

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				$this->relations($collection)
			);
		} finally {
			self::assertTrue($collection->hasChanges());
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationRepresentationSyncThrows(): void
	{
		$users = $this->usersWithProfile();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedRelatedReference($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(SyncException::class);

		try {
			(new FlushExecutor($executor))->flush(
				$this->trackedMap($this->tracked($this->representation(['name' => 'after', 'profile' => 'bad']), $this->ownerBindingWithProfile($record))),
				$this->records($record),
				new RelatedCollectionMap(),
				$this->references($reference)
			);
		} finally {
			self::assertTrue($reference->hasChanges());
			self::assertSame([], $executor->getCommands());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationPlanningThrows(): void
	{
		$users = $this->usersWithProfile(false);
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedRelatedReference($record);
		$executor = new RecordingCommandExecutor();

		$this->expectException(\ON\Data\ORM\Exception\RelationPersistenceException::class);

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				new RelatedCollectionMap(),
				$this->references($reference)
			);
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
		$collection = new RelatedCollection($owner, 'posts', $this->bindingFor($child));
		$collection->add($item);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($item, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->relations($collection)
		);

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
		$collection = new RelatedCollection($owner, 'posts', $this->bindingFor($child));
		$collection->add($postRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1, ['id' => 5]),
		]);

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($postRepresentation, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->relations($collection)
		);

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
		$collection = new RelatedCollection($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1, ['id' => 3]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->relations($collection)
		);

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
		$collection = new RelatedCollection($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 3]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->relations($collection)
		);

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
		$collection = new RelatedCollection($owner, 'tags', $this->bindingFor($target));
		$collection->add($tagRepresentation);
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 10]),
			new CommandResult(1),
		]);

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($tagRepresentation, $this->bindingFor($target))),
			$this->records($owner, $target),
			$this->relations($collection)
		);

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
		$collection = new RelatedCollection($owner, 'posts', $this->bindingFor($child), RelationCollectionState::FULLY_LOADED, [$item]);
		$collection->remove($item);
		$executor = new RecordingCommandExecutor();

		(new FlushExecutor($executor))->flush(
			$this->trackedMap($this->tracked($item, $this->bindingFor($child))),
			$this->records($owner, $child),
			$this->relations($collection)
		);

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

		(new FlushExecutor($executor))->flush(
			$this->trackedMap(
				$this->tracked($profileRepresentation, $this->bindingFor($target)),
				$this->tracked($ownerRepresentation, $this->ownerBindingWithProfile($owner))
			),
			$this->records($owner, $target),
			new RelatedCollectionMap(),
			new RelatedReferenceMap()
		);

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
		$collection = $this->changedRelatedCollection($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('scalar failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('scalar failed');

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				$this->relations($collection)
			);
		} finally {
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testReferenceChangesAreNotClearedIfScalarRecordFlushThrows(): void
	{
		$record = RecordState::clean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'before']);
		$record->setValue('name', 'dirty');
		$reference = $this->changedRelatedReference($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('scalar failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('scalar failed');

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				new RelatedCollectionMap(),
				$this->references($reference)
			);
		} finally {
			self::assertTrue($reference->hasChanges());
		}
	}

	public function testRelationChangesAreNotClearedIfRelationCommandExecutionThrows(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'before']);
		$collection = $this->changedRelatedCollection($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('relation command failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('relation command failed');

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				$this->relations($collection)
			);
		} finally {
			self::assertTrue($collection->hasChanges());
		}
	}

	public function testReferenceChangesAreNotClearedIfRelationCommandExecutionThrows(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$record = RecordState::clean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'before']);
		$reference = $this->changedRelatedReference($record);
		$executor = new class () implements CommandExecutorInterface {
			public function execute(CommandInterface $command): CommandResult
			{
				throw new LogicException('relation command failed');
			}
		};

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('relation command failed');

		try {
			(new FlushExecutor($executor))->flush(
				new TrackedRepresentationMap(),
				$this->records($record),
				new RelatedCollectionMap(),
				$this->references($reference)
			);
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
			$binding->addField(new RepresentationFieldBinding($path, $field));
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
		foreach ($binding->getFields() as $fieldBinding) {
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

	private function relations(RelatedCollection ...$collections): RelatedCollectionMap
	{
		$map = new RelatedCollectionMap();
		foreach ($collections as $collection) {
			$map->add($collection);
		}

		return $map;
	}

	private function references(RelatedReference ...$references): RelatedReferenceMap
	{
		$map = new RelatedReferenceMap();
		foreach ($references as $reference) {
			$map->add($reference);
		}

		return $map;
	}

	private function changedRelatedCollection(RecordState $owner): RelatedCollection
	{
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding());
		$collection->add(new stdClass());

		return $collection;
	}

	private function changedRelatedReference(RecordState $owner): RelatedReference
	{
		$reference = new RelatedReference($owner, 'profile', $this->postBinding());
		$reference->set(new stdClass());

		return $reference;
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function ownerBindingWithPosts(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState($record, 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postBinding()
		));

		return $binding;
	}

	private function ownerBindingWithProfile(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forState($record, 'profile'),
			RepresentationRelationCardinality::ONE,
			$this->postBinding()
		));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
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
			->field('user_id', 'int')->end()
			->end();
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
			->field('user_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');

		return [$users, $profiles];
	}

	private function bindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		foreach (array_keys($record->getValues()) as $field) {
			$field = (string) $field;
			$binding->addField(new RepresentationFieldBinding($field, RecordFieldRef::forState($record, $field)));
		}

		return $binding;
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
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
