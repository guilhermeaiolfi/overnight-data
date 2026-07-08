<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationSyncer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;

final class SessionTest extends TestCase
{
	use OrmFixture;

	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testSessionCreatesEmptyRecordAndRepresentationMaps(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame([], $session->getRecords()->getAll());
		self::assertSame([], iterator_to_array($session->getRepresentations()->getAll(), false));
		self::assertSame([], $session->getToManyRelations()->getAll());
		self::assertSame([], $session->getToOneRelations()->getAll());
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

	public function testIdentifyCreatesKeyOnlyObjectTrackedAsCleanExisting(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();

		$post = $session->identify($posts, ['id' => 123]);

		self::assertInstanceOf(stdClass::class, $post);
		self::assertSame(123, $post->id);
		$tracked = $session->getRepresentations()->get($post);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $session->getRecords()->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertSame(['id' => 123], $record->getValues());
	}

	public function testIdentifyTracksProvidedObjectAsCleanExisting(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$post = $this->representation(['id' => 123, 'title' => 'Existing']);

		$result = $session->identify($posts, ['id' => 123], $post, $this->postTemplateSchemaFor($posts));

		self::assertSame($post, $result);
		$tracked = $session->getRepresentations()->get($post);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $session->getRecords()->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertSame(['id' => 123, 'title' => 'Existing'], $record->getValues());
	}

	public function testFailedIdentifyWithWrongSchemaDoesNotLeavePartialSessionState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$key = $posts->getKey(['id' => 123]);
		$post = $this->representation(['id' => 123, 'title' => 'Existing']);

		try {
			$session->identify($posts, $key, $post, $this->templateSchema());
			self::fail('Expected identify to reject a schema targeting the wrong collection.');
		} catch (StateException) {
		}

		self::assertFalse($session->getRecords()->hasKey($key));
		self::assertFalse($session->getRepresentations()->has($post));
	}

	public function testIdentifySupportsCompositeKeys(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$collection = $this->compositeMemberships();

		$membership = $session->identify($collection, ['user_id' => 10, 'group_id' => 20]);

		$tracked = $session->getRepresentations()->get($membership);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $session->getRecords()->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertSame(['user_id' => 10, 'group_id' => 20], $record->getKey()?->getValues());
	}

	public function testIdentifyReusesAlreadyTrackedCleanOrDirtyRecordWithSameKey(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$record = $session->trackClean($posts->getKey(123), ['id' => 123, 'title' => 'Before']);
		$record->setValue('title', 'Dirty');
		$post = $this->representation(['id' => 123, 'title' => 'Object']);

		$session->identify($posts, ['id' => 123], $post, $this->postTemplateSchemaFor($posts));

		$tracked = $session->getRepresentations()->get($post);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertSame($record, $session->getRecords()->getFromRepresentation($tracked));
		self::assertTrue($record->isDirty());
	}

	public function testIdentifyThrowsWhenSameKeyIsAlreadyTrackedAsRemoved(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$record = $session->trackClean($posts->getKey(123), ['id' => 123, 'title' => 'Before']);
		$record->markRemoved();

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('removed');

		$session->identify($posts, ['id' => 123]);
	}

	public function testIdentifyReturnsAlreadyTrackedObjectForSameKeyAndThrowsForDifferentKey(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$post = $this->representation(['id' => 123, 'title' => 'Existing']);
		$session->identify($posts, ['id' => 123], $post, $this->postTemplateSchemaFor($posts));

		self::assertSame($post, $session->identify($posts, ['id' => 123], $post));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('different record');

		$session->identify($posts, ['id' => 456], $post);
	}

	public function testAdoptTracksRepresentationAndRecordThroughAdopter(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);

		$tracked = $session->adopt($representation, $this->templateSchema(), $record);

		self::assertSame($tracked, $session->getRepresentations()->get($representation));
		self::assertSame($record, $session->getRecords()->getByStateHash($record->getStateHash()));
		$fieldItems = $tracked->getFieldItems();
		self::assertCount(1, $fieldItems);
		self::assertSame($record, $fieldItems[0]->getRecord());
		self::assertSame($record->getRevision(), $fieldItems[0]->getBaselineRevision());
	}

	public function testFailedAdoptWithWrongSchemaDoesNotLeavePartialSessionState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$posts = $this->posts();
		$key = $posts->getKey(['id' => 123]);
		$record = RecordState::clean($key, ['id' => 123, 'title' => 'Existing']);
		$post = $this->representation(['id' => 123, 'title' => 'Existing']);

		try {
			$session->adopt($post, $this->templateSchema(), $record);
			self::fail('Expected adopt to reject a schema targeting the wrong collection.');
		} catch (StateException) {
		}

		self::assertFalse($session->getRecords()->hasKey($key));
		self::assertFalse($session->getRepresentations()->has($post));
	}

	public function testAdoptRejectsAdoptingSameRepresentationTwiceThroughExistingBehavior(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);

		$session->adopt($representation, $this->templateSchema(), $record);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('already tracked');

		$session->adopt($representation, $this->templateSchema(), $record);
	}

	public function testSyncTrackedRootAdoptsUntrackedRelatedManyItems(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

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
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithProfile($users, $profiles), $owner);

		$result = $session->sync($ownerRepresentation);

		$trackedProfile = $session->getRepresentations()->get($profileRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedProfile);
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncTrackedRootOnlySynchronizesThatRepresentation(): void
	{
		$users = $this->users();
		$session = new Session(new RecordingCommandExecutor());
		$first = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$second = $session->trackClean($users->getKey(20), ['id' => 20, 'name' => 'B1']);
		$firstRepresentation = $this->representation(['name' => 'A2']);
		$secondRepresentation = $this->representation(['name' => 'B2']);
		$session->adopt($firstRepresentation, $this->templateSchema(), $first);
		$session->adopt($secondRepresentation, $this->templateSchema(), $second);

		$result = $session->sync($firstRepresentation);

		self::assertCount(1, $result->getSyncPlans());
		self::assertSame('A2', $first->getValue('name'));
		self::assertTrue($first->isDirty());
		self::assertSame('B1', $second->getValue('name'));
		self::assertTrue($second->isClean());

		$secondResult = $session->sync($secondRepresentation);

		self::assertCount(1, $secondResult->getSyncPlans());
		self::assertSame('B2', $second->getValue('name'));
		self::assertTrue($second->isDirty());
	}

	public function testSyncUntrackedRootWithSchemaOnlySynchronizesAdoptedRootGraph(): void
	{
		$users = $this->users();
		$session = new Session(new RecordingCommandExecutor());
		$root = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$other = $session->trackClean($users->getKey(20), ['id' => 20, 'name' => 'B1']);
		$rootRepresentation = $this->representation(['id' => 10, 'name' => 'A2']);
		$otherRepresentation = $this->representation(['name' => 'B2']);
		$session->adopt($otherRepresentation, $this->templateSchema(), $other);

		$result = $session->sync($rootRepresentation, $this->userTemplateSchemaFor($users));

		self::assertCount(1, $result->getSyncPlans());
		self::assertSame('A2', $root->getValue('name'));
		self::assertTrue($root->isDirty());
		self::assertSame('B1', $other->getValue('name'));
		self::assertTrue($other->isClean());
		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($rootRepresentation));

		$otherResult = $session->sync($otherRepresentation);

		self::assertCount(1, $otherResult->getSyncPlans());
		self::assertSame('B2', $other->getValue('name'));
		self::assertTrue($other->isDirty());
	}

	public function testSyncUntrackedRootWithSchemaTracksRootAndSyncsScalarValues(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'New User']);

		$result = $session->sync($representation, $this->templateSchema());

		$tracked = $session->getRepresentations()->get($representation);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertFalse($result->hasChanges());
		self::assertSame('New User', $session->getRecords()->getFromRepresentation($tracked)?->getValue('name'));
	}

	public function testSyncUntrackedRootWithCompleteKeyTracksRootAsCleanExisting(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['id' => 10, 'name' => 'Existing User']);

		$result = $session->sync($representation, $this->userTemplateSchemaFor($this->users()));

		$tracked = $session->getRepresentations()->get($representation);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $session->getRecords()->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertFalse($result->hasChanges());
	}

	public function testSyncUntrackedRootWithoutCompleteKeyTracksRootAsNew(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['id' => null, 'name' => 'New User']);

		$session->sync($representation, $this->userTemplateSchemaFor($this->users()));

		$tracked = $session->getRepresentations()->get($representation);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $session->getRecords()->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
	}

	public function testSyncUntrackedRootWithSchemaTracksManyRelatedPlainObjects(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);

		$result = $session->sync($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts));

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($postRepresentation));
		self::assertSame([$postRepresentation], $session->getToManyRelations()->get($owner, 'posts')?->getItems());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithSchemaTracksManyRelatedObjectWithCompleteKeyAsNew(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);

		$session->sync($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts));

		$trackedPost = $session->getRepresentations()->get($postRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedPost);
		$post = $session->getRecords()->getFromRepresentation($trackedPost);
		self::assertInstanceOf(RecordState::class, $post);
		self::assertTrue($post->isNew());
		self::assertSame(5, $post->getValue('id'));
	}

	public function testSyncUntrackedRootWithSchemaTracksManyRelatedObjectWithoutCompleteKeyAsNew(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$postRepresentation = $this->representation(['id' => null, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);

		$session->sync($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts));

		$trackedPost = $session->getRepresentations()->get($postRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedPost);
		$post = $session->getRecords()->getFromRepresentation($trackedPost);
		self::assertInstanceOf(RecordState::class, $post);
		self::assertTrue($post->isNew());
	}

	public function testSyncUntrackedRootWithRelationOnlySingleCollectionSchemaSucceeds(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$ownerRepresentation = $this->representation(['posts' => []]);
		$schema = new RepresentationSchema($users);
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			$this->postTemplateSchemaFor($posts),
			true
		));

		$result = $session->sync($ownerRepresentation, $schema);

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertSame([], $owner->getValues());
		self::assertSame([], $session->getToManyRelations()->get($owner, 'posts')?->getItems());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithSchemaTracksOneRelatedPlainTarget(): void
	{
		[$users, $profiles] = $this->usersWithDefaultHasOneProfile();
		$session = new Session(new RecordingCommandExecutor());
		$profileRepresentation = $this->representation(['id' => 5, 'label' => 'Profile', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'profile' => $profileRepresentation]);

		$result = $session->sync($ownerRepresentation, $this->ownerTemplateSchemaWithProfile($users, $profiles));

		$trackedOwner = $session->getRepresentations()->get($ownerRepresentation);
		self::assertInstanceOf(RepresentationState::class, $trackedOwner);
		$owner = $session->getRecords()->getFromRepresentation($trackedOwner);
		self::assertInstanceOf(RecordState::class, $owner);
		self::assertInstanceOf(RepresentationState::class, $session->getRepresentations()->get($profileRepresentation));
		self::assertSame($profileRepresentation, $session->getToOneRelations()->get($owner, 'profile')?->getTarget());
		self::assertCount(1, $result->getRelationChanges());
	}

	public function testSyncUntrackedRootWithMixedCollectionFieldsThrows(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'Owner', 'title' => 'Post']);
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a schema targeting one collection');

		$session->sync($representation, $schema);
	}

	public function testSyncUntrackedRootWithMixedFieldAndRelationOwnerCollectionsThrows(): void
	{
		[$posts, $users] = $this->postsWithDefaultBelongsToAuthor();
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'Owner', 'author' => null]);
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema(
			'author',
			$posts,
			'author',
			$this->userTemplateSchemaFor($users)
		));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a schema targeting one collection');

		$session->sync($representation, $schema);
	}

	public function testSyncUntrackedRootWithEmptySchemaThrows(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a schema targeting one collection');

		$session->sync(new stdClass(), new RepresentationSchema($this->users()));
	}

	public function testSyncAlreadyRepresentationStateWithMixedSchemaIsNotAffectedByUntrackedRootGuard(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$userRecord = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRecord = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Original', 'user_id' => null]);
		$representation = $this->representation(['name' => 'Owner Updated', 'title' => 'Post Updated']);
		$schema = new RepresentationSchema($userRecord->getCollection());
		$schema->addField(new RepresentationFieldSchema('name', $userRecord->getCollection(), 'name'));
		$schema->addField(new RepresentationFieldSchema('title', $postRecord->getCollection(), 'title'));
		$session->getRepresentations()->add($representation, new RepresentationState(
			$schema,
			[
				new RepresentationFieldStateItem($schema->getField('name'), $userRecord, 'name', $userRecord->getRevision()),
				new RepresentationFieldStateItem($schema->getField('title'), $postRecord, 'title', $postRecord->getRevision()),
			]
		));

		$result = $session->sync($representation, new RepresentationSchema($userRecord->getCollection()));

		self::assertTrue($result->hasChanges());
		self::assertSame('Owner Updated', $userRecord->getValue('name'));
		self::assertSame('Post Updated', $postRecord->getValue('title'));
	}

	public function testSyncWithSchemaDoesNotFlush(): void
	{
		$executor = new RecordingCommandExecutor();
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$owner->setValue('name', 'Owner Updated');
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner Updated', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

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

	public function testRemoveMarksTrackedCleanRecordRemoved(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);

		$session->remove($record);

		self::assertTrue($record->isRemoved());
		self::assertSame([$record], $session->getRecords()->getAll());
	}

	public function testRemoveObjectMarksSingleConcreteTrackedRecordRemoved(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$session->adopt($representation, $this->templateSchema(), $record);

		$session->remove($representation);

		self::assertTrue($record->isRemoved());
	}

	public function testRemoveObjectThrowsForUntrackedObject(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('untracked');

		$session->remove(new stdClass());
	}

	public function testRemoveObjectThrowsWhenSchemaHasNoConcreteRecordState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$session->getRepresentations()->add($representation, new RepresentationState(new RepresentationSchema($this->users()), []));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('does not resolve to a concrete record state');

		$session->remove($representation);
	}

	public function testRemoveObjectThrowsForMixedProjectionSchema(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$session = new Session(new RecordingCommandExecutor());
		$userRecord = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRecord = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Post', 'user_id' => 10]);
		$representation = $this->representation(['name' => 'Owner', 'title' => 'Post']);
		$schema = new RepresentationSchema($userRecord->getCollection());
		$schema->addField(new RepresentationFieldSchema('name', $userRecord->getCollection(), 'name'));
		$schema->addField(new RepresentationFieldSchema('title', $postRecord->getCollection(), 'title'));
		$session->getRepresentations()->add($representation, new RepresentationState($schema, [
			new RepresentationFieldStateItem($schema->getField('name'), $userRecord, 'name', $userRecord->getRevision()),
			new RepresentationFieldStateItem($schema->getField('title'), $postRecord, 'title', $postRecord->getRevision()),
		]));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('multiple record states');

		$session->remove($representation);
	}

	public function testGetRelationsReturnsOwnedMap(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame($session->getToManyRelations(), $session->getToManyRelations());
	}

	public function testGetReferencesReturnsOwnedMap(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		self::assertSame($session->getToOneRelations(), $session->getToOneRelations());
	}

	public function testTrackRelationAddsAndReturnsSameCollection(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$collection = $this->changedToManyRelationState(RecordState::new($this->usersWithPosts()));

		$result = $session->trackToManyRelation($collection);

		self::assertSame($collection, $result);
		self::assertSame([$collection], $session->getToManyRelations()->getAll());
	}

	public function testTrackReferenceAddsAndReturnsSameReference(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$reference = $this->changedToOneRelationState(RecordState::new($this->usersWithProfile()));

		$result = $session->trackToOneRelation($reference);

		self::assertSame($reference, $result);
		self::assertSame([$reference], $session->getToOneRelations()->getAll());
	}

	public function testFlushSynchronizesRepresentationChangesAndExecutesCommandsUsingOwnedMaps(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$session->adopt($representation, $this->templateSchema(), $record);
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

	public function testFlushRemovedNewUnkeyedRecordExecutesNoCommandAndRemovesItFromOwnedMap(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackNew($this->users(), ['name' => 'A1']);
		$session->remove($record);

		$session->flush();

		self::assertSame([], $executor->getCommands());
		self::assertSame([], $session->getRecords()->getAll());
	}

	public function testSecondFlushAfterDeletionDoesNotDeleteAgain(): void
	{
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->remove($record);

		$session->flush();
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(DeleteCommand::class, $executor->getCommands()[0]);
	}

	public function testFlushDoesNotClearRepresentationStates(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$tracked = $session->adopt($representation, $this->templateSchema(), $record);
		$representation->name = 'A2';

		$session->flush();

		self::assertSame($tracked, $session->getRepresentations()->get($representation));
	}

	public function testClearClearsAllRuntimeStores(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackNew($this->users(), ['name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$session->adopt($representation, $this->templateSchema(), $record);
		$session->trackToManyRelation(new ToManyRelationState($record, 'posts', new RepresentationSchema($record->getCollection())));
		$session->trackToOneRelation(new ToOneRelationState($record, 'profile', new RepresentationSchema($record->getCollection())));

		$session->clear();

		self::assertSame([], $session->getRecords()->getAll());
		self::assertSame([], iterator_to_array($session->getRepresentations()->getAll(), false));
		self::assertSame([], $session->getToManyRelations()->getAll());
		self::assertSame([], $session->getToOneRelations()->getAll());
	}

	public function testFlushPassesOwnedRelationsToFlushExecutor(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$record = $session->trackClean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->trackToManyRelation($this->changedToManyRelationState($record));

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
		$session->trackToOneRelation($this->changedToOneRelationState($record));

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(TestCommand::class, $executor->getCommands()[0]);
	}

	public function testRelationChangesAreClearedAfterSuccessfulFlushThroughSession(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$collection = $session->trackToManyRelation($this->changedToManyRelationState($record));

		$session->flush();

		self::assertFalse($collection->hasChanges());
	}

	public function testReferenceChangesAreClearedAfterSuccessfulFlushThroughSession(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->usersWithProfile()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$reference = $session->trackToOneRelation($this->changedToOneRelationState($record));

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
		$session->adopt($item, $this->tagTemplateSchemaFor($tags), $target);
		$collection = new ToManyRelationState($owner, 'tags', $this->schemaFor($target));
		$collection->add($item);
		$session->trackToManyRelation($collection);

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
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);
		$session->adopt($postRepresentation, $this->postTemplateSchemaFor($posts), $child);

		$session->flush();

		$collection = $session->getToManyRelations()->get($owner, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
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
		$session->adopt($authorRepresentation, $this->userTemplateSchemaFor($users), $target);
		$session->adopt($ownerRepresentation, $this->postTemplateSchemaWithAuthor($posts, $users), $owner);

		$session->flush();

		$reference = $session->getToOneRelations()->get($owner, 'author');
		self::assertInstanceOf(ToOneRelationState::class, $reference);
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
		$session->adopt($ownerRepresentation, $this->postTemplateSchemaWithAuthor($posts, $users), $owner);
		$session->trackToOneRelation(new ToOneRelationState($owner, 'author', $this->userTemplateSchemaFor($users), $baselineAuthor));

		$session->flush();

		$reference = $session->getToOneRelations()->get($owner, 'author');
		self::assertInstanceOf(ToOneRelationState::class, $reference);
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
		$session->adopt($profileRepresentation, $this->profileTemplateSchemaFor($profiles), $target);
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithProfile($users, $profiles), $owner);

		$session->flush();

		$reference = $session->getToOneRelations()->get($owner, 'profile');
		self::assertInstanceOf(ToOneRelationState::class, $reference);
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
		$session->adopt($representation, $this->templateSchema(), $record);

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
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

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
		$postRepresentation = $this->representation(['id' => null, 'title' => 'Post', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

		$session->sync($ownerRepresentation);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		self::assertInstanceOf(InsertCommand::class, $executor->getCommands()[0]);
	}

	public function testRemoveAfterGraphSyncAdoptsExistingChildProducesDeleteOnFlush(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$postRepresentation = $this->representation(['id' => 5, 'title' => 'Existing title', 'user_id' => 10]);
		$session->existing($postRepresentation);
		$ownerRepresentation = $this->representation(['name' => 'Owner', 'posts' => [$postRepresentation]]);
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

		$session->sync($ownerRepresentation);
		$session->remove($postRepresentation);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof DeleteCommand) {
			self::fail('Expected a delete command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
	}

	public function testSyncRepresentationStateWorksWithoutSchema(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$first = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$firstRepresentation = $this->representation(['name' => 'A2']);
		$session->adopt($firstRepresentation, $this->templateSchema(), $first);

		$session->sync($firstRepresentation);

		self::assertSame('A2', $first->getValue('name'));
	}

	public function testSyncUnRepresentationStateWithoutSchemaThrowsSyncException(): void
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
		$session->adopt($representation, $this->templateSchema(), $record);

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
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);

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
		$session->adopt($ownerRepresentation, $this->ownerTemplateSchemaWithPosts($users, $posts), $owner);
		$session->adopt($postRepresentation, $this->postTemplateSchemaFor($posts), $child);

		$syncResult = $session->sync();
		$session->flush();

		self::assertCount(1, $syncResult->getRelationChanges());
		self::assertCount(1, $executor->getCommands());
		$collection = $session->getToManyRelations()->get($owner, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
		self::assertSame([$postRepresentation], $collection->getItems());
		self::assertFalse($collection->hasChanges());
	}

	public function testAddingNewChildToPartiallyLoadedHasManyFlushesWithoutDeletingUnknownChildren(): void
	{
		[$users, $posts] = $this->usersWithDefaultHasManyPosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$newChild = $this->representation(['id' => null, 'title' => 'New child', 'user_id' => null]);
		$session->adopt($newChild, $this->postTemplateSchemaFor($posts), $session->trackNew($posts, ['title' => 'New child', 'user_id' => null]));
		$collection = $session->trackToManyRelation(new ToManyRelationState($owner, 'posts', $this->postTemplateSchemaFor($posts)));

		$collection->add($newChild);
		$session->flush();
		$session->flush();

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command for the new child.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['title' => 'New child', 'user_id' => 10], $command->getValues());
	}

	public function testRemovingKnownChildFromPartiallyLoadedHasManyOnlyDetachesThatChild(): void
	{
		[$users, $posts] = $this->usersWithNullablePosts();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = $session->trackClean($posts->getKey(5), ['id' => 5, 'title' => 'Known child', 'user_id' => 10]);
		$childRepresentation = $this->representation(['id' => 5, 'title' => 'Known child', 'user_id' => 10]);
		$session->adopt($childRepresentation, $this->postTemplateSchemaFor($posts), $child);
		$collection = $session->trackToManyRelation(new ToManyRelationState(
			$owner,
			'posts',
			$this->postTemplateSchemaFor($posts),
			[$childRepresentation],
		));

		$collection->remove($childRepresentation);
		$session->flush();
		$session->flush();

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertFalse($collection->hasChanges());
		self::assertSame([], $collection->getItems());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command detaching only the known child.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['user_id' => null], $command->getChanges());
	}

	public function testNewRootWithExistingBelongsToAndMixedManyToManyItemsFlushesInDependencyOrder(): void
	{
		[$posts, $users, $tags, $through] = $this->postsWithAuthorAndTags();
		$executor = new RecordingCommandExecutor(results: [
			new CommandResult(1, ['id' => 50]),
			new CommandResult(1, ['id' => 7]),
			new CommandResult(1),
			new CommandResult(1),
		]);
		$session = new Session($executor);
		$author = $session->identify($users, ['id' => 10]);
		$existingTag = $session->identify($tags, ['id' => 3]);
		$newTag = $this->representation(['id' => null, 'label' => 'New']);
		$post = $this->representation([
			'id' => null,
			'title' => 'Draft',
			'author_id' => null,
			'author' => $author,
			'tags' => [$existingTag, $newTag],
		]);

		$session->sync($post, $this->postSchemaWithAuthorAndTags($posts, $users, $tags));
		$session->flush();

		self::assertCount(4, $executor->getCommands());
		$postInsert = $executor->getCommands()[0];
		$newTagInsert = $executor->getCommands()[1];
		$existingTagLink = $executor->getCommands()[2];
		$newTagLink = $executor->getCommands()[3];
		self::assertInstanceOf(InsertCommand::class, $postInsert);
		self::assertSame($posts, $postInsert->getCollection());
		self::assertSame(['title' => 'Draft', 'author_id' => 10], $postInsert->getValues());
		self::assertInstanceOf(InsertCommand::class, $newTagInsert);
		self::assertSame($tags, $newTagInsert->getCollection());
		self::assertSame(['label' => 'New'], $newTagInsert->getValues());
		self::assertInstanceOf(InsertCommand::class, $existingTagLink);
		self::assertSame($through, $existingTagLink->getCollection());
		self::assertSame(['post_id' => 50, 'tag_id' => 3], $existingTagLink->getValues());
		self::assertInstanceOf(InsertCommand::class, $newTagLink);
		self::assertSame($through, $newTagLink->getCollection());
		self::assertSame(['post_id' => 50, 'tag_id' => 7], $newTagLink->getValues());
	}

	public function testAddingNewChildToPartiallyLoadedCompositeHasManyFlushesWithoutDeletingUnknownChildren(): void
	{
		[$owners, $children] = $this->compositeOwnersWithChildren();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($owners->getKey(['tenant_id' => 7, 'user_id' => 10]), [
			'tenant_id' => 7,
			'user_id' => 10,
			'name' => 'Owner',
		]);
		$newChild = $this->representation(['label' => 'Composite child', 'tenant_ref' => null, 'user_ref' => null]);
		$session->adopt($newChild, $this->compositeChildSchemaFor($children), $session->trackNew($children, [
			'label' => 'Composite child',
			'tenant_ref' => null,
			'user_ref' => null,
		]));
		$collection = $session->trackToManyRelation(new ToManyRelationState($owner, 'children', $this->compositeChildSchemaFor($children)));

		$collection->add($newChild);
		$session->flush();
		$session->flush();

		self::assertTrue($collection->isPartiallyLoaded());
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command for the new composite child.');
		}

		self::assertSame($children, $command->getCollection());
		self::assertSame(['label' => 'Composite child', 'tenant_ref' => 7, 'user_ref' => 10], $command->getValues());
	}

	public function testRemovingIdentifiedChildFromUnloadedManyToManyUnlinksWithoutLoadingCollection(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$tag = $session->identify($tags, ['id' => 3]);
		$collection = $session->trackToManyRelation(new ToManyRelationState($owner, 'tags', $this->tagTemplateSchemaFor($tags)));

		$collection->remove($tag);
		$session->flush();
		$session->flush();

		self::assertFalse($collection->isFullyLoaded());
		self::assertFalse($collection->hasChanges());
		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof DeleteCommand) {
			self::fail('Expected a through delete command.');
		}

		self::assertSame($through, $command->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $command->getIdentity());
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

	private function templateSchema(): RepresentationSchema
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));

		return $schema;
	}

	private function ownerTemplateSchemaWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			$this->postTemplateSchemaFor($posts),
			false
		));

		return $schema;
	}

	private function ownerTemplateSchemaWithProfile(CollectionInterface $users, CollectionInterface $profiles): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema(
			'profile',
			$users,
			'profile',
			$this->profileTemplateSchemaFor($profiles)
		));

		return $schema;
	}

	private function postTemplateSchemaWithAuthor(CollectionInterface $posts, CollectionInterface $users): RepresentationSchema
	{
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$schema->addRelation(new RepresentationRelationSchema(
			'author',
			$posts,
			'author',
			$this->userTemplateSchemaFor($users)
		));

		return $schema;
	}

	private function userTemplateSchemaFor(CollectionInterface $users): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		return $schema;
	}

	private function postTemplateSchemaFor(CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('id', $posts, 'id'));
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$schema->addField(new RepresentationFieldSchema('user_id', $posts, 'user_id'));

		return $schema;
	}

	private function profileTemplateSchemaFor(CollectionInterface $profiles): RepresentationSchema
	{
		$schema = new RepresentationSchema($profiles);
		$schema->addField(new RepresentationFieldSchema('id', $profiles, 'id'));
		$schema->addField(new RepresentationFieldSchema('label', $profiles, 'label'));
		$schema->addField(new RepresentationFieldSchema('user_id', $profiles, 'user_id'));

		return $schema;
	}

	private function changedToManyRelationState(RecordState $owner): ToManyRelationState
	{
		$collection = new ToManyRelationState($owner, 'posts', $this->postSchema());
		$collection->add(new stdClass());

		return $collection;
	}

	private function changedToOneRelationState(RecordState $owner): ToOneRelationState
	{
		$reference = new ToOneRelationState($owner, 'profile', $this->postSchema());
		$reference->set(new stdClass());

		return $reference;
	}

	private function schemaFor(RecordState $record): RepresentationSchema
	{
		$schema = new RepresentationSchema($record->getCollection());
		foreach (array_keys($record->getValues()) as $field) {
			$field = (string) $field;
			$schema->addField(new RepresentationFieldSchema($field, $record->getCollection(), $field));
		}

		return $schema;
	}

	private function tagTemplateSchemaFor(CollectionInterface $tags): RepresentationSchema
	{
		$schema = new RepresentationSchema($tags);
		$schema->addField(new RepresentationFieldSchema('id', $tags, 'id'));
		$schema->addField(new RepresentationFieldSchema('label', $tags, 'label'));

		return $schema;
	}

	private function postSchemaWithAuthorAndTags(
		CollectionInterface $posts,
		CollectionInterface $users,
		CollectionInterface $tags,
	): RepresentationSchema {
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('id', $posts, 'id'));
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$schema->addField(new RepresentationFieldSchema('author_id', $posts, 'author_id'));
		$schema->addRelation(new RepresentationRelationSchema(
			'author',
			$posts,
			'author',
			$this->userTemplateSchemaFor($users)
		));
		$schema->addRelation(new RepresentationRelationSchema(
			'tags',
			$posts,
			'tags',
			$this->tagTemplateSchemaFor($tags),
			false
		));

		return $schema;
	}

	private function compositeChildSchemaFor(CollectionInterface $children): RepresentationSchema
	{
		$schema = new RepresentationSchema($children);
		$schema->addField(new RepresentationFieldSchema('label', $children, 'label'));
		$schema->addField(new RepresentationFieldSchema('tenant_ref', $children, 'tenant_ref'));
		$schema->addField(new RepresentationFieldSchema('user_ref', $children, 'user_ref'));

		return $schema;
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
	private function usersWithNullablePosts(): array
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
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id')->nullable(true);

		return [$users, $posts];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface, 2: CollectionInterface, 3: CollectionInterface}
	 */
	private function postsWithAuthorAndTags(): array
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->end();
		$registry->collection('tags')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->end();
		$registry->collection('post_tag')
			->field('post_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('author_id', 'int')->end();
		$posts->belongsTo('author', 'users')->innerKey('author_id')->outerKey('id');
		$relation = $posts->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('post_tag')
				->innerKey('post_id')
				->outerKey('tag_id')
				->end();

		self::assertInstanceOf(M2MRelation::class, $relation);
		$users = $registry->getCollection('users');
		$tags = $registry->getCollection('tags');
		$through = $registry->getCollection('post_tag');
		self::assertInstanceOf(CollectionInterface::class, $users);
		self::assertInstanceOf(CollectionInterface::class, $tags);
		self::assertInstanceOf(CollectionInterface::class, $through);

		return [$posts, $users, $tags, $through];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function compositeOwnersWithChildren(): array
	{
		$registry = new Registry();
		$children = $registry->collection('children')
			->primaryKey('tenant_ref', 'user_ref')
			->field('label', 'string')->end()
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end();
		$owners = $registry->collection('owners')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('name', 'string')->end();
		$owners->hasMany('children', 'children')
			->innerKey(['tenant_id', 'user_id'])
			->outerKey(['tenant_ref', 'user_ref']);

		return [$owners, $children];
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

	private function compositeMemberships(): CollectionInterface
	{
		return (new Registry())
			->collection('memberships')
			->primaryKey('user_id', 'group_id')
			->field('user_id', 'int')->end()
			->field('group_id', 'int')->end();
	}
}
