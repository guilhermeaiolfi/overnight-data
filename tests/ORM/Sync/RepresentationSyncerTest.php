<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;

final class RepresentationSyncerTest extends TestCase
{
	use OrmFixture;

	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testSyncAllRepresentationStatesUpdatesScalarRecordStateValues(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);

		$result = $this->syncer()->sync(
			$this->context(
				$this->representations($this->tracked($this->representation(['name' => 'A2']), $this->userSchemaFor($record), [$record])),
				$this->records($record)
			)
		);

		self::assertSame('A2', $record->getValue('name'));
		self::assertTrue($result->hasChanges());
	}

	public function testSyncOneRepresentationStateUpdatesOnlyThatRepresentation(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstRepresentation = $this->representation(['name' => 'A2']);
		$secondRepresentation = $this->representation(['name' => 'B2']);

		$this->syncer()->sync(
			$this->context(
				$this->representations(
					$this->tracked($firstRepresentation, $this->userSchemaFor($first), [$first]),
					$this->tracked($secondRepresentation, $this->userSchemaFor($second), [$second])
				),
				$this->records($first, $second)
			),
			$firstRepresentation
		);

		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B1', $second->getValue('name'));
	}

	public function testSyncOneUnRepresentationStateThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('untracked');

		$this->syncer()->sync(new SessionContext(), new stdClass());
	}

	public function testSyncAllUpdatesManyRelationPathsIntoToManyRelationState(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$item = new stdClass();
		$toManyRelations = new RelationStateStore();

		$this->syncer()->sync(
			$this->context(
				$this->representations(
					$this->tracked($this->representation(['name' => 'Owner', 'posts' => [$item]]), $this->ownerSchemaWithPosts($owner), [$owner]),
					$this->tracked($item, new RepresentationSchema($this->posts()))
				),
				$this->records($owner),
				$toManyRelations
			),
		);

		$collection = $toManyRelations->get($owner, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
		self::assertSame([$item], $collection->getAdded());
	}

	public function testSyncAllUpdatesOneRelationPathsIntoToOneRelationState(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$target = new stdClass();
		$toOneRelations = new RelationStateStore();

		$this->syncer()->sync(
			$this->context(
				$this->representations(
					$this->tracked($this->representation(['name' => 'Owner', 'profile' => $target]), $this->ownerSchemaWithProfile($owner), [$owner]),
					$this->tracked($target, new RepresentationSchema($this->profiles()))
				),
				$this->records($owner),
				toOneRelations: $toOneRelations
			),
		);

		$reference = $toOneRelations->get($owner, 'profile');
		self::assertInstanceOf(ToOneRelationState::class, $reference);
		self::assertSame($target, $reference->getTarget());
	}

	public function testSyncReturnsSyncResultWithScalarPlansAndTouchedRelationChanges(): void
	{
		$owner = RecordState::new($this->users(), ['name' => 'Owner']);
		$item = new stdClass();

		$result = $this->syncer()->sync(
			$this->context(
				$this->representations(
					$this->tracked($this->representation(['name' => 'Changed', 'posts' => [$item]]), $this->ownerSchemaWithPosts($owner), [$owner]),
					$this->tracked($item, new RepresentationSchema($this->posts()))
				),
				$this->records($owner)
			),
		);

		self::assertCount(2, $result->getSyncPlans());
		self::assertCount(1, $result->getRelationChanges());
		self::assertTrue($result->hasChanges());
	}

	public function testSyncDoesNotPlanRelationPersistenceFlushRecordsExecuteCommandsOrClearRelationChanges(): void
	{
		$owner = RecordState::clean($this->usersWithPosts()->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$item = new stdClass();
		$toManyRelations = new RelationStateStore();
		$executor = new RecordingCommandExecutor();

		$this->syncer()->sync(
			$this->context(
				$this->representations(
					$this->tracked($this->representation(['name' => 'Changed', 'posts' => [$item]]), $this->ownerSchemaWithPosts($owner), [$owner]),
					$this->tracked($item, new RepresentationSchema($this->posts()))
				),
				$this->records($owner),
				$toManyRelations
			),
		);

		$collection = $toManyRelations->get($owner, 'posts');
		self::assertInstanceOf(ToManyRelationState::class, $collection);
		self::assertSame(0, RecordingRelationPersistencePlanner::$calls);
		self::assertSame([], $executor->getCommands());
		self::assertTrue($owner->isDirty());
		self::assertTrue($collection->hasChanges());
	}

	public function testRepresentationSyncerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/RepresentationSyncer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
	}

	private function ownerSchemaWithPosts(RecordState $record): RepresentationSchema
	{
		$schema = $this->userSchemaFor($record);
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$record->getCollection(),
			'posts',
			$this->postSchema()
		));

		return $schema;
	}

	private function ownerSchemaWithProfile(RecordState $record): RepresentationSchema
	{
		$schema = $this->userSchemaFor($record);
		$schema->addRelation(new RepresentationRelationSchema(
			'profile',
			$record->getCollection(),
			'profile',
			$this->profileSchema()
		));

		return $schema;
	}

	private function syncer(): RepresentationSyncer
	{
		return new RepresentationSyncer();
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
