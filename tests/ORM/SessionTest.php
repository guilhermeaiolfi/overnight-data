<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationSyncer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;

final class SessionTest extends TestCase
{
	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testSessionCreatesEmptyRecordAndRepresentationMaps(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame([], $session->getRecords()->getAll());
		self::assertSame([], iterator_to_array($session->getRepresentations()->getAll(), false));
		self::assertSame([], $session->getRelations()->getAll());
		self::assertSame([], $session->getReferences()->getAll());
	}

	public function testDefaultFlushExecutorUsesSessionRepresentationSyncer(): void
	{
		$syncer = new RepresentationSyncer();
		$session = new Session(new RecordingCommandExecutor(), syncer: $syncer);

		$sessionReflection = new ReflectionClass($session);
		$flusherProperty = $sessionReflection->getProperty('flusher');
		$flusher = $flusherProperty->getValue($session);

		$flusherReflection = new ReflectionClass($flusher);
		$syncerProperty = $flusherReflection->getProperty('syncer');

		self::assertSame($syncer, $syncerProperty->getValue($flusher));
	}

	public function testTrackRecordAddsExistingRecordAndReturnsSameInstance(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);

		$result = $session->trackRecord($record);
		$secondResult = $session->trackRecord($record);

		self::assertSame($record, $result);
		self::assertSame($record, $secondResult);
		self::assertSame([$record], $session->getRecords()->getAll());
	}

	public function testTrackNewCreatesTracksAndReturnsNewRecordState(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$record = $session->trackNew($this->users(), ['name' => 'A1']);

		self::assertTrue($record->isNew());
		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame([$record], $session->getRecords()->getAll());
	}

	public function testTrackCleanCreatesTracksAndReturnsCleanKeyedRecordState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$users = $this->users();
		$key = $users->getKey(10);

		$record = $session->trackClean($key, ['id' => 10, 'name' => 'A1']);

		self::assertTrue($record->isClean());
		self::assertSame($key, $record->getKey());
		self::assertSame($record, $session->getRecords()->getByKey($key));
	}

	public function testAdoptTracksRepresentationAndRecordThroughAdopter(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);

		$tracked = $session->adopt($representation, $this->templateBinding(), $record);

		self::assertSame($tracked, $session->getRepresentations()->get($representation));
		self::assertSame($record, $session->getRecords()->getByStateHash($record->getStateHash()));
		self::assertSame([$record->getStateHash() => $record->getRevision()], $tracked->getBaselineRevisions());
	}

	public function testAdoptRejectsAdoptingSameRepresentationTwiceThroughExistingBehavior(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);

		$session->adopt($representation, $this->templateBinding(), $record);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('already tracked');

		$session->adopt($representation, $this->templateBinding(), $record);
	}

	public function testSyncTrackedRootAdoptsUntrackedRelatedManyItems(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);

		$result = $session->sync($ownerRepresentation);

		$trackedPost = $session->getRepresentations()->get($postRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedPost);
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncTrackedRootAdoptsUntrackedOneTarget(): void
	{
		[$users, $profiles] = $this->usersWithDefaultHasOneProfile();
		$session = new Session(new RecordingCommandExecutor());
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$profileRepresentation = $this->representation(['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'profile' => $profileRepresentation]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithProfile($users, $profiles), $owner);

		$result = $session->sync($ownerRepresentation);

		$trackedProfile = $session->getRepresentations()->get($profileRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedProfile);
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithBindingTracksRootAndSyncsScalarValues(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'New User']);

		$result = $session->sync($representation, $this->templateBinding());

		$tracked = $session->getRepresentations()->get($representation);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertFalse($result->hasChanges());
		self::assertSame('New User', $session->getRecords()->getFromRepresentation($tracked)?->getValue('name'));
	}

	public function testSyncUntrackedRootWithBindingTracksManyRelatedPlainObjects(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);

		$result = $session->sync($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts));

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($postRepresentation));
		self::assertSame([$postRepresentation], $session->getRelations()->get($owner, 'posts')?->getItems());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithRelationOnlySingleCollectionBindingSucceeds(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$ownerRepresentation = $this->representation(['posts' => []]);
		$binding = new RepresentationBinding();
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forCollection($users, 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postTemplateBindingFor($posts),
			RelationCollectionState::FULLY_LOADED
		));

		$result = $session->sync($ownerRepresentation, $binding);

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertSame([], $owner->getValues());
		self::assertSame([], $session->getRelations()->get($owner, 'posts')?->getItems());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithBindingTracksOneRelatedPlainTarget(): void
	{
		[$users, $profiles] = $this->usersWithDefaultHasOneProfile();
		$session = new Session(new RecordingCommandExecutor());
		$profileRepresentation = $this->representation(['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'profile' => $profileRepresentation]);

		$result = $session->sync($ownerRepresentation, $this->ownerTemplateBindingWithProfile($users, $profiles));

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($profileRepresentation));
		self::assertSame($profileRepresentation, $session->getReferences()->get($owner, 'profile')?->getTarget());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithMixedCollectionFieldsThrows(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'Owner', 'title' => 'Post']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($posts, 'title')));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a binding targeting one collection');

		$session->sync($representation, $binding);
	}

	public function testSyncUntrackedRootWithMixedFieldAndRelationOwnerCollectionsThrows(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'Owner', 'author' => null]);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));
		$binding->addRelation(new RepresentationRelationBinding(
			'author',
			RecordRelationRef::forCollection($posts, 'author'),
			RepresentationRelationCardinality::ONE,
			$this->userTemplateBindingFor($users)
		));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a binding targeting one collection');

		$session->sync($representation, $binding);
	}

	public function testSyncUntrackedRootWithEmptyBindingThrows(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a binding targeting one collection');

		$session->sync(new stdClass(), new RepresentationBinding());
	}

	public function testSyncAlreadyRepresentationStateWithMixedBindingIsNotAffectedByUntrackedRootGuard(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$userRecord = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRecord = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Original', 'user_id' => null]);
		$representation = $this->representation(['name' => 'Owner Updated', 'title' => 'Post Updated']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($userRecord, 'name')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::forState($postRecord, 'title')));
		$session->getRepresentations()->add($representation, new RepresentationState(
			$binding,
			[
				$userRecord->getStateHash() => $userRecord->getRevision(),
				$postRecord->getStateHash() => $postRecord->getRevision(),
			]
		));

		$result = $session->sync($representation, new RepresentationBinding());

		self::assertTrue($result->hasChanges());
		self::assertSame('Owner Updated', $userRecord->getValue('name'));
		self::assertSame('Post Updated', $postRecord->getValue('title'));
	}

	public function testSyncWithBindingDoesNotFlush(): void
	{
		$executor = new RecordingCommandExecutor();
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$owner->setValue('name', 'Owner Updated');
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner Updated', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);

		$session->sync($ownerRepresentation);

		self::assertSame([], $executor->getCommands());
		self::assertTrue($owner->isDirty());
	}

	public function testRemoveRecordMarksTrackedCleanRecordRemovedAndKeepsItInMapBeforeFlush(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);

		$session->removeRecord($record);

		self::assertTrue($record->isRemoved());
		self::assertSame([$record], $session->getRecords()->getAll());
	}

	public function testRemoveRecordTracksUntrackedRecordBeforeMarkingItRemoved(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);

		$session->removeRecord($record);

		self::assertTrue($record->isRemoved());
		self::assertSame([$record], $session->getRecords()->getAll());
	}

	public function testGetRelationsReturnsOwnedMap(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame($session->getRelations(), $session->getRelations());
	}

	public function testGetReferencesReturnsOwnedMap(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame($session->getReferences(), $session->getReferences());
	}

	public function testTrackRelationAddsAndReturnsSameCollection(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$collection = $this->changedRelatedCollection(RecordState::new($this->usersWithPosts()));

		$result = $session->trackRelation($collection);

		self::assertSame($collection, $result);
		self::assertSame([$collection], $session->getRelations()->getAll());
	}

	public function testTrackReferenceAddsAndReturnsSameReference(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$reference = $this->changedRelatedReference(RecordState::new($this->usersWithProfile()));

		$result = $session->trackReference($reference);

		self::assertSame($reference, $result);
		self::assertSame([$reference], $session->getReferences()->getAll());
	}

	public function testFlushSynchronizesRepresentationChangesAndExecutesCommandsUsingOwnedMaps(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$session->adopt($representation, $this->templateBinding(), $record);
		$representation->name = 'A2';

		$result = $session->flush();

		self::assertCount(1, $result->getSyncPlans());
		self::assertCount(1, $result->getCommandResults());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame(['name' => 'A2'], $command->getChanges());
		self::assertTrue($record->isClean());
	}

	public function testFlushRemovesSuccessfullyDeletedRecordsFromOwnedMapThroughRecordFlusher(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->removeRecord($record);

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
		self::assertSame([], $session->getRecords()->getAll());
	}

	public function testFlushDoesNotClearRepresentationStates(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$tracked = $session->adopt($representation, $this->templateBinding(), $record);
		$representation->name = 'A2';

		$session->flush();

		self::assertSame($tracked, $session->getRepresentations()->get($representation));
	}

	public function testClearClearsAllRuntimeStores(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackNew($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$session->adopt($representation, $this->templateBinding(), $record);
		$session->trackRelation(new RelatedCollection($record, 'posts', new RepresentationBinding()));
		$session->trackReference(new RelatedReference($record, 'profile', new RepresentationBinding()));

		$session->clear();

		self::assertSame([], $session->getRecords()->getAll());
		self::assertSame([], iterator_to_array($session->getRepresentations()->getAll(), false));
		self::assertSame([], $session->getRelations()->getAll());
		self::assertSame([], $session->getReferences()->getAll());
	}

	public function testFlushPassesOwnedRelationsToFlushExecutor(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->trackRelation($this->changedRelatedCollection($record));

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(TestCommand::class, $executor->getCommands()[0]);
	}

	public function testFlushPassesOwnedReferencesToFlushExecutor(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->trackReference($this->changedRelatedReference($record));

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(TestCommand::class, $executor->getCommands()[0]);
	}

	public function testRelationChangesAreClearedAfterSuccessfulFlushThroughSession(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$collection = $session->trackRelation($this->changedRelatedCollection($record));

		$session->flush();

		self::assertFalse($collection->hasChanges());
	}

	public function testReferenceChangesAreClearedAfterSuccessfulFlushThroughSession(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$reference = $session->trackReference($this->changedRelatedReference($record));

		$session->flush();

		self::assertFalse($reference->hasChanges());
	}

	public function testFlushPersistsM2MRelationAddThroughDefaultPlanner(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$owner->setValue('name', 'Ada Lovelace');
		$target = $session->trackClean($tags->getKey(3), ['id' => 3, 'label' => 'math']);
		$item = $this->representation(['id' => 3, 'label' => 'math']);
		$session->adopt($item, $this->tagTemplateBindingFor($tags), $target);
		$collection = new RelatedCollection($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);
		$session->trackRelation($collection);

		$session->flush();

		self::assertCount(2, $executor->getCommands());
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
		$relationCommand = $executor->getCommands()[1];
		if (! $relationCommand instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		self::assertSame($through, $relationCommand->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $relationCommand->getValues());
		self::assertFalse($collection->hasChanges());
	}

	public function testFlushInfersHasManyRelationAddFromRepresentationStateGraph(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);
		$session->adopt($postRepresentation, $this->postTemplateBindingFor($posts), $child);

		$session->flush();

		$collection = $session->getRelations()->get($owner, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertSame([$postRepresentation], $collection->getItems());
		self::assertFalse($collection->hasChanges());
		self::assertSame(10, $child->getValue('user_id'));
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['user_id' => 10], $command->getChanges());
	}

	public function testFlushInfersBelongsToReferenceSetFromRepresentationStateGraph(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'author_id' => null]);
		$target = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$authorRepresentation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$ownerRepresentation = $this->representation(['title' => 'Post', 'author' => $authorRepresentation]);
		$session->adopt($authorRepresentation, $this->userTemplateBindingFor($users), $target);
		$session->adopt($ownerRepresentation, $this->postTemplateBindingWithAuthor($posts, $users), $owner);

		$session->flush();

		$reference = $session->getReferences()->get($owner, 'author');
		self::assertInstanceOf(RelatedReference::class, $reference);
		self::assertSame($authorRepresentation, $reference->getTarget());
		self::assertFalse($reference->hasChanges());
		self::assertSame(10, $owner->getValue('author_id'));
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['author_id' => 10], $command->getChanges());
	}

	public function testFlushInfersNullableBelongsToReferenceClearFromRepresentationStateGraph(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'author_id' => 10]);
		$baselineAuthor = new stdClass();
		$ownerRepresentation = $this->representation(['title' => 'Post', 'author' => null]);
		$session->adopt($ownerRepresentation, $this->postTemplateBindingWithAuthor($posts, $users), $owner);
		$session->trackReference(new RelatedReference($owner, 'author', $this->userTemplateBindingFor($users), $baselineAuthor));

		$session->flush();

		$reference = $session->getReferences()->get($owner, 'author');
		self::assertInstanceOf(RelatedReference::class, $reference);
		self::assertNull($reference->getTarget());
		self::assertFalse($reference->hasChanges());
		self::assertNull($owner->getValue('author_id'));
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['author_id' => null], $command->getChanges());
	}

	public function testFlushInfersHasOneReferenceSetFromRepresentationStateGraph(): void
	{
		[$users, $profiles] = $this->usersWithDefaultHasOneProfile();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$target = $session->trackClean($profiles->getKey(5), ['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$profileRepresentation = $this->representation(['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'profile' => $profileRepresentation]);
		$session->adopt($profileRepresentation, $this->profileTemplateBindingFor($profiles), $target);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithProfile($users, $profiles), $owner);

		$session->flush();

		$reference = $session->getReferences()->get($owner, 'profile');
		self::assertInstanceOf(RelatedReference::class, $reference);
		self::assertSame($profileRepresentation, $reference->getTarget());
		self::assertFalse($reference->hasChanges());
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

	public function testSyncSynchronizesAllRepresentationStatesWithoutFlushingCommands(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A2']);
		$session->adopt($representation, $this->templateBinding(), $record);

		$result = $session->sync();

		self::assertSame('A2', $record->getValue('name'));
		self::assertTrue($result->hasChanges());
		self::assertSame([], $executor->getCommands());
		self::assertTrue($record->isDirty());
	}

	public function testSyncTrackedRootAfterAddingRelatedPlainObjectTracksAndSynchronizesIt(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);

		$result = $session->sync($ownerRepresentation);

		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($postRepresentation));
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testFlushSucceedsAfterExplicitSyncAdoptsNewRelatedObject(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);

		$session->sync($ownerRepresentation);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
	}

	public function testSyncRepresentationStateWorksWithoutBinding(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$first = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$firstRepresentation = $this->representation(['name' => 'A2']);
		$session->adopt($firstRepresentation, $this->templateBinding(), $first);

		$session->sync($firstRepresentation);

		self::assertSame('A2', $first->getValue('name'));
	}

	public function testSyncUnRepresentationStateWithoutBindingThrowsSyncException(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('untracked');

		$session->sync(new stdClass());
	}

	public function testFlushStillPerformsSyncAutomaticallyAfterPublicSyncWasAdded(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A2']);
		$session->adopt($representation, $this->templateBinding(), $record);

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(UpdateCommand::class, $executor->getCommands()[0]);
	}

	public function testSyncWithoutObjectAndFlushStillDoNotAutoAdoptNewRelatedObject(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);

		try {
			$session->sync();
			self::fail('Expected sync to reject the untracked related object.');
		} catch (SyncException $exception) {
			self::assertStringContainsString('not tracked', $exception->getMessage());
		}

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('not tracked');

		$session->flush();
	}

	public function testCallingSyncBeforeFlushDoesNotDuplicateRelationChanges(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateBindingWithPosts($users, $posts), $owner);
		$session->adopt($postRepresentation, $this->postTemplateBindingFor($posts), $child);

		$syncResult = $session->sync();
		$session->flush();

		self::assertCount(1, $syncResult->getRelationChanges());
		self::assertCount(1, $executor->getCommands());
		$collection = $session->getRelations()->get($owner, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertSame([$postRepresentation], $collection->getItems());
		self::assertFalse($collection->hasChanges());
	}

	public function testSessionSourceDoesNotMentionDeferredOrmRuntimeConcepts(): void
	{
		$source = file_get_contents(__DIR__ . '/../../src/ORM/Session.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('EntityManager', $source);
		self::assertStringNotContainsString('UnitOfWork', $source);
		self::assertStringNotContainsString('Repository', $source);
		self::assertStringNotContainsString('Transaction', $source);
		self::assertStringNotContainsString('SQL', $source);
		self::assertStringNotContainsString('Database', $source);
	}

	public function testSessionHasNoPublicAdoptGraphMethod(): void
	{
		self::assertFalse(method_exists(Session::class, 'adoptGraph'));
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

	private function templateBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		return $binding;
	}

	private function ownerTemplateBindingWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forCollection($users, 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postTemplateBindingFor($posts),
			RelationCollectionState::UNLOADED
		));

		return $binding;
	}

	private function ownerTemplateBindingWithProfile(CollectionInterface $users, CollectionInterface $profiles): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forCollection($users, 'profile'),
			RepresentationRelationCardinality::ONE,
			$this->profileTemplateBindingFor($profiles)
		));

		return $binding;
	}

	private function postTemplateBindingWithAuthor(CollectionInterface $posts, CollectionInterface $users): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($posts, 'title')));
		$binding->addRelation(new RepresentationRelationBinding(
			'author',
			RecordRelationRef::forCollection($posts, 'author'),
			RepresentationRelationCardinality::ONE,
			$this->userTemplateBindingFor($users)
		));

		return $binding;
	}

	private function userTemplateBindingFor(CollectionInterface $users): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($users, 'id')));
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));

		return $binding;
	}

	private function postTemplateBindingFor(CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($posts, 'id')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($posts, 'title')));
		$binding->addField(new RepresentationFieldBinding('user_id', RecordFieldRef::template($posts, 'user_id')));

		return $binding;
	}

	private function profileTemplateBindingFor(CollectionInterface $profiles): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($profiles, 'id')));
		$binding->addField(new RepresentationFieldBinding('label', RecordFieldRef::template($profiles, 'label')));
		$binding->addField(new RepresentationFieldBinding('user_id', RecordFieldRef::template($profiles, 'user_id')));

		return $binding;
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

	private function bindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		foreach (array_keys($record->getValues()) as $field) {
			$field = (string) $field;
			$binding->addField(new RepresentationFieldBinding($field, RecordFieldRef::forState($record, $field)));
		}

		return $binding;
	}

	private function tagTemplateBindingFor(CollectionInterface $tags): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($tags, 'id')));
		$binding->addField(new RepresentationFieldBinding('label', RecordFieldRef::template($tags, 'label')));

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

	private function usersWithPosts(): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('id')
			->persistencePlanner(RecordingRelationPersistencePlanner::class);

		return $users;
	}

	private function usersWithProfile(): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('profiles')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasOne('profile', 'profiles')
			->innerKey('id')
			->outerKey('user_id')
			->persistencePlanner(RecordingRelationPersistencePlanner::class);

		return $users;
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
		$relation = $users->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('user_tag')
				->innerKey('user_id')
				->outerKey('tag_id')
				->end();

		self::assertInstanceOf(M2MRelation::class, $relation);
		$tags = $registry->getCollection('tags');
		$through = $registry->getCollection('user_tag');
		self::assertInstanceOf(CollectionInterface::class, $tags);
		self::assertInstanceOf(CollectionInterface::class, $through);

		return [$users, $tags, $through];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithDefaultHasManyPosts(): array
	{
		$registry = new Registry();
		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end()
			->end();
		$posts = $registry->getCollection('posts');
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		self::assertInstanceOf(CollectionInterface::class, $posts);
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		return [$users, $posts];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithDefaultHasOneProfile(): array
	{
		$registry = new Registry();
		$registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->field('user_id', 'int')->end()
			->end();
		$profiles = $registry->getCollection('profiles');
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		self::assertInstanceOf(CollectionInterface::class, $profiles);
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');

		return [$users, $profiles];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function postsWithDefaultBelongsToAuthor(): array
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->end();
		$users = $registry->getCollection('users');
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('author_id', 'int')->end();
		self::assertInstanceOf(CollectionInterface::class, $users);
		$posts->belongsTo('author', 'users')->innerKey('author_id')->outerKey('id');

		return [$posts, $users];
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
