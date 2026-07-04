<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;
use Tests\ON\Data\Support\RecordingCommandExecutor;

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
		self::assertSame([], $session->getRepresentations()->getAll());
		self::assertSame([], $session->getRelations()->getAll());
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

	public function testTrackRelationAddsAndReturnsSameCollection(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$collection = $this->changedRelatedCollection(RecordState::new($this->usersWithPosts()));

		$result = $session->trackRelation($collection);

		self::assertSame($collection, $result);
		self::assertSame([$collection], $session->getRelations()->getAll());
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

	public function testFlushDoesNotClearTrackedRepresentations(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$representation = $this->representation(['name' => 'A1']);
		$tracked = $session->adopt($representation, $this->templateBinding(), $record);
		$representation->name = 'A2';

		$session->flush();

		self::assertSame($tracked, $session->getRepresentations()->get($representation));
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

	public function testRelationChangesAreClearedAfterSuccessfulFlushThroughSession(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$session = new Session(new RecordingCommandExecutor());
		$record = $session->trackClean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$collection = $session->trackRelation($this->changedRelatedCollection($record));

		$session->flush();

		self::assertFalse($collection->hasChanges());
	}

	public function testFlushPersistsM2MRelationAddThroughDefaultPlanner(): void
	{
		[$users, $tags, $through] = $this->usersWithTags();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$owner->setValue('name', 'Ada Lovelace');
		$target = $session->trackClean($tags->getKey(3), ['id' => 3, 'label' => 'math']);
		$item = new stdClass();
		$session->adopt($item, $this->bindingFor($target), $target);
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

	private function changedRelatedCollection(RecordState $owner): RelatedCollection
	{
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding());
		$collection->add(new stdClass());

		return $collection;
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

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
