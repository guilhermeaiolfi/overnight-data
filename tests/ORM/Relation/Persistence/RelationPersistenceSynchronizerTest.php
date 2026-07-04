<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Relation\Persistence\RelationPersistenceSynchronizer;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomRelation;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;

final class RelationPersistenceSynchronizerTest extends TestCase
{
	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testNoChangedRelationsReturnsEmptyResult(): void
	{
		$result = (new RelationPersistenceSynchronizer())->sync(
			new RelatedCollectionMap(),
			new RecordStateMap(),
			new TrackedRepresentationMap()
		);

		self::assertSame([], $result->getCollections());
		self::assertSame([], $result->getCommands());
	}

	public function testChangedRelationWithNoPlannerThrows(): void
	{
		$users = $this->registryWithRelation(useCustomRelation: true)->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedRelatedCollection(RecordState::new($users));
		$relations = new RelatedCollectionMap();
		$relations->add($collection);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('no configured persistence planner');

		(new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());
	}

	public function testChangedRelationWithMissingDefinitionThrows(): void
	{
		$users = (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
		$collection = $this->changedRelatedCollection(RecordState::new($users));
		$relations = new RelatedCollectionMap();
		$relations->add($collection);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('no relation definition');

		(new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());
	}

	public function testChangedRelationWithPlannerInvokesPlannerWithContextRelationAndCollection(): void
	{
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedRelatedCollection(RecordState::new($users));
		$relations = new RelatedCollectionMap();
		$relations->add($collection);
		$records = new RecordStateMap();
		$representations = new TrackedRepresentationMap();

		$result = (new RelationPersistenceSynchronizer())->sync($relations, $records, $representations);

		self::assertSame(1, RecordingRelationPersistencePlanner::$calls);
		self::assertSame($collection, RecordingRelationPersistencePlanner::$collections[0]);
		self::assertSame($users->getRelations()->get('posts'), RecordingRelationPersistencePlanner::$relations[0]);
		self::assertSame($records, RecordingRelationPersistencePlanner::$contexts[0]->getRecords());
		self::assertSame($representations, RecordingRelationPersistencePlanner::$contexts[0]->getRepresentations());
		self::assertSame($relations, RecordingRelationPersistencePlanner::$contexts[0]->getRelations());
		self::assertSame([$collection], $result->getCollections());
	}

	public function testPlannerCanAddCommandsToResult(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$relations = new RelatedCollectionMap();
		$relations->add($this->changedRelatedCollection(RecordState::new($users)));

		$result = (new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());

		self::assertCount(1, $result->getCommands());
		self::assertInstanceOf(TestCommand::class, $result->getCommands()[0]);
	}

	public function testChangedM2MRelationWithDefaultPlannerProducesThroughCommand(): void
	{
		[$users, $tags, $through] = $this->registryWithM2MRelation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new RelatedCollection($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);
		$relations = new RelatedCollectionMap();
		$relations->add($collection);

		$result = (new RelationPersistenceSynchronizer())->sync(
			$relations,
			$this->records($owner, $target),
			$this->trackedMap($this->tracked($item, $target)),
		);

		self::assertCount(1, $result->getCommands());
		$command = $result->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		self::assertSame($through, $command->getCollection());
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $command->getValues());
	}

	public function testChangedHasManyRelationWithDefaultPlannerMutatesChildRecordState(): void
	{
		[$users, $posts] = $this->registryWithHasManyRelation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => null]);
		$item = new stdClass();
		$collection = new RelatedCollection($owner, 'posts', $this->bindingFor($child));
		$collection->add($item);
		$relations = new RelatedCollectionMap();
		$relations->add($collection);

		$result = (new RelationPersistenceSynchronizer())->sync(
			$relations,
			$this->records($owner, $child),
			$this->trackedMap($this->tracked($item, $child)),
		);

		self::assertSame(10, $child->getValue('user_id'));
		self::assertSame([], $result->getCommands());
	}

	public function testPlannerCanMutateRecordState(): void
	{
		RecordingRelationPersistencePlanner::$mutateOwnerField = 'name';
		RecordingRelationPersistencePlanner::$mutateOwnerValue = 'planned';
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'before']);
		$relations = new RelatedCollectionMap();
		$relations->add($this->changedRelatedCollection($owner));

		(new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());

		self::assertSame('planned', $owner->getValue('name'));
		self::assertTrue($owner->isDirty());
	}

	public function testSynchronizerDoesNotClearRelatedCollectionChanges(): void
	{
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedRelatedCollection(RecordState::new($users));
		$relations = new RelatedCollectionMap();
		$relations->add($collection);

		(new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());

		self::assertTrue($collection->hasChanges());
	}

	public function testSynchronizerDoesNotExecuteCommands(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$relations = new RelatedCollectionMap();
		$relations->add($this->changedRelatedCollection(RecordState::new($users)));

		$result = (new RelationPersistenceSynchronizer())->sync($relations, new RecordStateMap(), new TrackedRepresentationMap());

		self::assertCount(1, $result->getCommands());
		self::assertSame(1, RecordingRelationPersistencePlanner::$calls);
	}

	public function testSynchronizerSourceStaysRelationGeneric(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../../src/ORM/Relation/Persistence/RelationPersistenceSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('M2MRelation', $source);
		self::assertStringNotContainsString('HasManyRelation', $source);
		self::assertStringNotContainsString('HasManyPersistencePlanner', $source);
		self::assertStringNotContainsString('getThrough', $source);
		self::assertStringNotContainsString('InsertCommand', $source);
		self::assertStringNotContainsString('DeleteCommand', $source);
	}

	private function registryWithRelation(?string $planner = null, bool $useCustomRelation = false): Registry
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$relation = $useCustomRelation
			? $users->relation('posts', CustomRelation::class)->collection('posts')
			: $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('id');
		if ($planner !== null) {
			$relation->persistencePlanner($planner);
		}

		return $registry;
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function registryWithHasManyRelation(): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		return [$users, $posts];
	}

	private function changedRelatedCollection(RecordState $owner): RelatedCollection
	{
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding());
		$collection->add(new stdClass());

		return $collection;
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function registryWithM2MRelation(): array
	{
		$registry = new Registry();
		$registry->collection('tags')->primaryKey('id')->field('id', 'int')->end()->end();
		$registry->collection('user_tag')
			->field('user_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
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

	private function records(RecordState ...$records): RecordStateMap
	{
		$map = new RecordStateMap();
		foreach ($records as $record) {
			$map->add($record);
		}

		return $map;
	}

	private function trackedMap(TrackedRepresentation ...$trackedRepresentations): TrackedRepresentationMap
	{
		$map = new TrackedRepresentationMap();
		foreach ($trackedRepresentations as $tracked) {
			$map->add($tracked);
		}

		return $map;
	}

	private function tracked(object $representation, RecordState $record): TrackedRepresentation
	{
		return new TrackedRepresentation($representation, $this->bindingFor($record), [
			$record->getStateHash() => $record->getRevision(),
		]);
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

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end();
	}
}
