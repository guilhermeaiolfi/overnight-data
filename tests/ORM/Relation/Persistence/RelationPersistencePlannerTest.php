<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\Relation\Persistence\RelationPersistenceResult;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomRelation;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;
use Tests\ON\Data\Support\Relation\RecordingRelationPersistencePlanner;
use Tests\ON\Data\Support\Relation\TestCommand;

final class RelationPersistencePlannerTest extends TestCase
{
	use OrmFixture;

	protected function setUp(): void
	{
		RecordingRelationPersistencePlanner::reset();
	}

	public function testNoChangedRelationsReturnsEmptyResult(): void
	{
		$result = $this->plan();

		self::assertSame([], $result->getChanges());
		self::assertSame([], $result->getCommands());
	}

	public function testChangedRelationWithNoPlannerThrows(): void
	{
		$users = $this->registryWithRelation(useCustomRelation: true)->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedToManyRelationState(RecordState::new($users));
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('no configured persistence planner');

		$this->plan($toManyRelations);
	}

	public function testChangedRelationWithMissingDefinitionThrows(): void
	{
		$users = (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
		$collection = $this->changedToManyRelationState(RecordState::new($users));
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('no relation definition');

		$this->plan($toManyRelations);
	}

	public function testChangedRelationWithPlannerInvokesPlannerWithContextRelationAndCollection(): void
	{
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedToManyRelationState(RecordState::new($users));
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$toOneRelations = new RelationStateStore();

		$result = $this->plan($toManyRelations, $toOneRelations, $records, $representations);

		self::assertSame(1, RecordingRelationPersistencePlanner::$calls);
		self::assertSame($collection, RecordingRelationPersistencePlanner::$collections[0]);
		self::assertSame($users->getRelations()->get('posts'), RecordingRelationPersistencePlanner::$relations[0]);
		self::assertSame($records, RecordingRelationPersistencePlanner::$contexts[0]->getRecords());
		self::assertSame($representations, RecordingRelationPersistencePlanner::$contexts[0]->getRepresentations());
		self::assertSame($toManyRelations, RecordingRelationPersistencePlanner::$contexts[0]->getToManyRelations());
		self::assertSame($toOneRelations, RecordingRelationPersistencePlanner::$contexts[0]->getToOneRelations());
		self::assertSame([$collection], $result->getChanges());
	}

	public function testPlannerCanAddCommandsToResult(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($this->changedToManyRelationState(RecordState::new($users)));

		$result = $this->plan($toManyRelations);

		self::assertCount(1, $result->getCommands());
		self::assertInstanceOf(TestCommand::class, $result->getCommands()[0]);
	}

	public function testChangedReferenceIsPlanned(): void
	{
		$registry = $this->registryWithOneRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$reference = $this->changedToOneRelationState(RecordState::new($users));
		$toOneRelations = new RelationStateStore();
		$toOneRelations->add($reference);

		$result = $this->plan(toOneRelations: $toOneRelations);

		self::assertSame(1, RecordingRelationPersistencePlanner::$calls);
		self::assertSame($reference, RecordingRelationPersistencePlanner::$changes[0]);
		self::assertSame([$reference], $result->getChanges());
	}

	public function testCollectionsArePlannedBeforeReferences(): void
	{
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedToManyRelationState(RecordState::new($users));
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);
		$reference = $this->changedToOneRelationState(RecordState::new($users), 'posts');
		$toOneRelations = new RelationStateStore();
		$toOneRelations->add($reference);

		$result = $this->plan($toManyRelations, $toOneRelations);

		self::assertSame([$collection, $reference], $result->getChanges());
		self::assertSame([$collection, $reference], RecordingRelationPersistencePlanner::$changes);
	}

	public function testUnchangedReferencesAreSkipped(): void
	{
		$registry = $this->registryWithOneRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$reference = new ToOneRelationState(RecordState::new($users), 'profile', $this->postSchema());
		$toOneRelations = new RelationStateStore();
		$toOneRelations->add($reference);

		$result = $this->plan(toOneRelations: $toOneRelations);

		self::assertSame(0, RecordingRelationPersistencePlanner::$calls);
		self::assertSame([], $result->getChanges());
	}

	public function testChangedM2MRelationWithDefaultPlannerProducesThroughCommand(): void
	{
		[$users, $tags, $through] = $this->registryWithM2MRelation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'tags', $this->schemaFor($target));
		$collection->add($item);
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);

		$result = $this->plan(
			$toManyRelations,
			records: $this->records($owner, $target),
			representations: $this->representations($this->tracked($item, $target)),
		);

		self::assertCount(1, $result->getCommands());
		$command = $result->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		self::assertSame($through, $command->getCollection());
		(new CommandValueResolver())->assertReady($command);
		self::assertSame(['user_id' => 10, 'tag_id' => 3], $command->getValues());
	}

	public function testChangedHasManyRelationWithDefaultPlannerMutatesChildRecordState(): void
	{
		[$users, $posts] = $this->registryWithHasManyRelation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => null]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'posts', $this->schemaFor($child));
		$collection->add($item);
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);

		$result = $this->plan(
			$toManyRelations,
			records: $this->records($owner, $child),
			representations: $this->representations($this->tracked($item, $child)),
		);

		self::assertSame(10, $child->getValue('user_id'));
		self::assertSame([], $result->getCommands());
	}

	public function testChangedBelongsToReferenceWithDefaultPlannerMutatesOwnerRecordState(): void
	{
		[$posts, $users] = $this->registryWithBelongsToRelation();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);
		$item = new stdClass();
		$reference = new ToOneRelationState($owner, 'author', $this->schemaFor($target));
		$reference->set($item);
		$toOneRelations = new RelationStateStore();
		$toOneRelations->add($reference);

		$result = $this->plan(
			toOneRelations: $toOneRelations,
			records: $this->records($owner, $target),
			representations: $this->representations($this->tracked($item, $target)),
		);

		self::assertSame(10, $owner->getValue('author_id'));
		self::assertSame([], $result->getCommands());
	}

	public function testChangedHasOneReferenceWithDefaultPlannerMutatesTargetRecordState(): void
	{
		[$users, $profiles] = $this->registryWithDefaultHasOneRelation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => null]);
		$item = new stdClass();
		$reference = new ToOneRelationState($owner, 'profile', $this->schemaFor($target));
		$reference->set($item);
		$toOneRelations = new RelationStateStore();
		$toOneRelations->add($reference);

		$result = $this->plan(
			toOneRelations: $toOneRelations,
			records: $this->records($owner, $target),
			representations: $this->representations($this->tracked($item, $target)),
		);

		self::assertSame(10, $target->getValue('user_id'));
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
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($this->changedToManyRelationState($owner));

		$this->plan($toManyRelations, records: new RecordStateStore());

		self::assertSame('planned', $owner->getValue('name'));
		self::assertTrue($owner->isDirty());
	}

	public function testPlannerDoesNotClearToManyRelationStateChanges(): void
	{
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$collection = $this->changedToManyRelationState(RecordState::new($users));
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($collection);

		$this->plan($toManyRelations, records: new RecordStateStore());

		self::assertTrue($collection->hasChanges());
	}

	public function testPlannerDoesNotExecuteCommands(): void
	{
		RecordingRelationPersistencePlanner::$addCommand = true;
		$registry = $this->registryWithRelation(RecordingRelationPersistencePlanner::class);
		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$toManyRelations = new RelationStateStore();
		$toManyRelations->add($this->changedToManyRelationState(RecordState::new($users)));

		$result = $this->plan($toManyRelations);

		self::assertCount(1, $result->getCommands());
		self::assertSame(1, RecordingRelationPersistencePlanner::$calls);
	}

	public function testPlannerSourceStaysRelationGeneric(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../../src/ORM/Relation/Persistence/RelationPersistencePlanner.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('M2MRelation', $source);
		self::assertStringNotContainsString('HasManyRelation', $source);
		self::assertStringNotContainsString('HasOneRelation', $source);
		self::assertStringNotContainsString('BelongsToRelation', $source);
		self::assertStringNotContainsString('HasManyPersistencePlanner', $source);
		self::assertStringNotContainsString('HasOnePersistencePlanner', $source);
		self::assertStringNotContainsString('BelongsToPersistencePlanner', $source);
		self::assertStringNotContainsString('getThrough', $source);
		self::assertStringNotContainsString('InsertCommand', $source);
		self::assertStringNotContainsString('DeleteCommand', $source);
	}

	private function plan(
		?RelationStateStore $toManyRelations = null,
		?RelationStateStore $toOneRelations = null,
		?RecordStateStore $records = null,
		?RepresentationStateStore $representations = null,
	): RelationPersistenceResult {
		return (new RelationPersistencePlanner())->plan($this->context(
			$representations,
			$records,
			$toManyRelations,
			$toOneRelations,
		));
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

	private function registryWithOneRelation(?string $planner = null): Registry
	{
		$registry = new Registry();
		$registry->collection('profiles')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end()->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$relation = $users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');
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
			->field('user_id', 'int')->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		return [$users, $posts];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function registryWithBelongsToRelation(): array
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('author_id', 'int')->end();
		$posts->belongsTo('author', 'users')->innerKey('author_id')->outerKey('id');

		return [$posts, $users];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function registryWithDefaultHasOneRelation(): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');

		return [$users, $profiles];
	}

	private function changedToManyRelationState(RecordState $owner): ToManyRelationState
	{
		$collection = new ToManyRelationState($owner, 'posts', $this->postSchema());
		$collection->add(new stdClass());

		return $collection;
	}

	private function changedToOneRelationState(RecordState $owner, string $relationName = 'profile'): ToOneRelationState
	{
		$reference = new ToOneRelationState($owner, $relationName, $this->postSchema());
		$reference->set(new stdClass());

		return $reference;
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

	private function tracked(object $representation, RecordState $record): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($schema = $this->schemaFor($record), $this->fieldItemsFor($schema, [$record]))
		);
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
}
