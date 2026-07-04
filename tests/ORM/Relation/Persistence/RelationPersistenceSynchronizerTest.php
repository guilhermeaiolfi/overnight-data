<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Relation\Persistence\RelationPersistenceSynchronizer;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentationMap;
use PHPUnit\Framework\TestCase;
use stdClass;
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
		$users = $this->registryWithRelation()->getCollection('users');
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

	private function registryWithRelation(?string $planner = null): Registry
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$relation = $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('id');
		if ($planner !== null) {
			$relation->persistencePlanner($planner);
		}

		return $registry;
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
		$binding->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end();
	}
}
