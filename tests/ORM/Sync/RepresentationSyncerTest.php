<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceMap;
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
use ON\Data\ORM\Sync\RepresentationSyncer;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class RepresentationSyncerTest extends TestCase
{
	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testSyncAllTrackedRepresentationsUpdatesScalarRecordStateValues(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);

		$result = $this->syncer()->sync(
			$this->trackedMap($this->tracked($this->representation(['name' => 'A2']), $this->binding($record))),
			$this->records($record),
			new RelatedCollectionMap(),
			new RelatedReferenceMap()
		);

		self::assertSame('A2', $record->getValue('name'));
		self::assertTrue($result->hasChanges());
	}

	public function testSyncOneTrackedRepresentationUpdatesOnlyThatRepresentation(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstRepresentation = $this->representation(['name' => 'A2']);
		$secondRepresentation = $this->representation(['name' => 'B2']);

		$this->syncer()->sync(
			$this->trackedMap(
				$this->tracked($firstRepresentation, $this->binding($first)),
				$this->tracked($secondRepresentation, $this->binding($second))
			),
			$this->records($first, $second),
			new RelatedCollectionMap(),
			new RelatedReferenceMap(),
			$firstRepresentation
		);

		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B1', $second->getValue('name'));
	}

	public function testSyncOneUntrackedRepresentationThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('untracked');

		$this->syncer()->sync(new TrackedRepresentationMap(), new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap(), new stdClass());
	}

	public function testSyncAllUpdatesManyRelationPathsIntoRelatedCollection(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$item = new stdClass();
		$relations = new RelatedCollectionMap();

		$this->syncer()->sync(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerBindingWithPosts($owner)),
				$this->tracked($item, new RepresentationBinding())
			),
			$this->records($owner),
			$relations,
			new RelatedReferenceMap()
		);

		$collection = $relations->get($owner, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertSame([$item], $collection->getAdded());
	}

	public function testSyncAllUpdatesOneRelationPathsIntoRelatedReference(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$target = new stdClass();
		$references = new RelatedReferenceMap();

		$this->syncer()->sync(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Owner', 'profile' => $target]), $this->ownerBindingWithProfile($owner)),
				$this->tracked($target, new RepresentationBinding())
			),
			$this->records($owner),
			new RelatedCollectionMap(),
			$references
		);

		$reference = $references->get($owner, 'profile');
		self::assertInstanceOf(RelatedReference::class, $reference);
		self::assertSame($target, $reference->getTarget());
	}

	public function testSyncReturnsSyncResultWithScalarPlansAndTouchedRelationChanges(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$item = new stdClass();

		$result = $this->syncer()->sync(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Changed', 'posts' => [$item]]), $this->ownerBindingWithPosts($owner)),
				$this->tracked($item, new RepresentationBinding())
			),
			$this->records($owner),
			new RelatedCollectionMap(),
			new RelatedReferenceMap()
		);

		self::assertCount(1, $result->getSyncPlans());
		self::assertCount(1, $result->getRelationChanges());
		self::assertTrue($result->hasChanges());
	}

	public function testSyncDoesNotPlanRelationPersistenceFlushRecordsExecuteCommandsOrClearRelationChanges(): void
	{
		$owner = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$item = new stdClass();
		$relations = new RelatedCollectionMap();
		$executor = new RecordingCommandExecutor();

		$this->syncer()->sync(
			$this->trackedMap(
				$this->tracked($this->representation(['name' => 'Changed', 'posts' => [$item]]), $this->ownerBindingWithPosts($owner)),
				$this->tracked($item, new RepresentationBinding())
			),
			$this->records($owner),
			$relations,
			new RelatedReferenceMap()
		);

		$collection = $relations->get($owner, 'posts');
		self::assertInstanceOf(RelatedCollection::class, $collection);
		self::assertSame(0, RecordingRelationPersistencePlanner::$calls);
		self::assertSame([], $executor->getCommands());
		self::assertTrue($owner->isDirty());
		self::assertTrue($collection->hasChanges());
	}

	public function testRepresentationSyncerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/RepresentationSyncer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
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

	private function records(RecordState ...$records): RecordStateMap
	{
		$map = new RecordStateMap();
		foreach ($records as $record) {
			$map->add($record);
		}

		return $map;
	}

	private function binding(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));

		return $binding;
	}

	private function ownerBindingWithPosts(RecordState $record): RepresentationBinding
	{
		$binding = $this->binding($record);
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState($record, 'posts'),
			RepresentationRelationCardinality::MANY,
			new RepresentationBinding()
		));

		return $binding;
	}

	private function ownerBindingWithProfile(RecordState $record): RepresentationBinding
	{
		$binding = $this->binding($record);
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forState($record, 'profile'),
			RepresentationRelationCardinality::ONE,
			new RepresentationBinding()
		));

		return $binding;
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

	private function syncer(): RepresentationSyncer
	{
		return new RepresentationSyncer();
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
}
