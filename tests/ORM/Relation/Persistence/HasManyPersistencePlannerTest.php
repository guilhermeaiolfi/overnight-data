<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\HasManyPersistencePlanner;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\State\ValueRef;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class HasManyPersistencePlannerTest extends TestCase
{
	public function testAddedTrackedChildCopiesOwnerKeyIntoChildOuterKey(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => null]);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertSame(10, $child->getValue('user_id'));
	}

	public function testAddedTrackedChildWithCustomKeyNamesWorks(): void
	{
		[$relation, $accounts, $posts] = $this->customKeyModel();
		$owner = RecordState::clean($accounts->getKey('account-1'), ['account_uuid' => 'account-1']);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_uuid' => null]);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertSame('account-1', $child->getValue('author_uuid'));
	}

	public function testAddedTrackedChildWithCompositeKeysWorks(): void
	{
		[$relation, $users, $posts] = $this->compositeKeyModel();
		$owner = RecordState::clean($users->getKey([7, 10]), ['tenant_id' => 7, 'user_id' => 10]);
		$child = RecordState::clean($posts->getKey([1, 2]), ['tenant_ref' => null, 'user_ref' => null]);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10], $child->getValues());
	}

	public function testAddedChildThatIsNewIsAllowedWhenOwnerKeysAreAvailable(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['id' => 10]);
		$child = RecordState::new($posts, ['title' => 'Draft']);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertTrue($child->isNew());
		self::assertSame(['title' => 'Draft', 'user_id' => 10], $child->getValues());
	}

	public function testAddedCleanChildBecomesDirtyWhenForeignKeyChanges(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => 8]);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertTrue($child->isDirty());
		self::assertSame(['user_id' => 10], $child->getDirtyValues());
	}

	public function testAddedChildWithSameForeignKeyDoesNotCreateDirtyValues(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => 10]);

		$this->planSingleAdd($relation, $owner, $child);

		self::assertTrue($child->isClean());
		self::assertSame([], $child->getDirtyValues());
	}

	public function testMissingTrackedChildRepresentationThrows(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$collection = new ToManyRelationState($owner, 'posts', $this->bindingFor($child));
		$collection->add(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'posts' child item is not tracked");

		$this->plan($relation, $collection, $this->records($owner, $child), new RepresentationStore());
	}

	public function testTrackedChildRepresentationThatCannotResolveToRecordStateThrows(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'posts', $this->bindingFor($child));
		$collection->add($item);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($posts, 'id')));
		$tracked = RepresentationStateObjectRegistry::remember($item, new RepresentationState($binding, []));

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('cannot be resolved to a record state');

		$this->plan($relation, $collection, $this->records($owner, $child), $this->trackedMap($tracked));
	}

	public function testMissingOwnerKeyValueWritesValueRef(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::new($users, []);
		$child = RecordState::new($posts, ['title' => 'Draft']);

		$this->planSingleAdd($relation, $owner, $child);

		$value = $child->getValue('user_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($owner, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testNullOwnerKeyValueWritesValueRef(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['id' => null]);
		$child = RecordState::new($posts, ['title' => 'Draft']);

		$this->planSingleAdd($relation, $owner, $child);

		$value = $child->getValue('user_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($owner, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testRemovedChildOnNullableRelationSetsChildOuterKeyFieldsToNull(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel(nullable: true);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => 10]);

		$this->planSingleRemove($relation, $owner, $child);

		self::assertNull($child->getValue('user_id'));
		self::assertSame(['user_id' => null], $child->getDirtyValues());
	}

	public function testRemovedChildOnNullableCompositeRelationSetsAllChildOuterKeysToNull(): void
	{
		[$relation, $users, $posts] = $this->compositeKeyModel(nullable: true);
		$owner = RecordState::clean($users->getKey([7, 10]), ['tenant_id' => 7, 'user_id' => 10]);
		$child = RecordState::clean($posts->getKey([7, 10]), ['tenant_ref' => 7, 'user_ref' => 10]);

		$this->planSingleRemove($relation, $owner, $child);

		self::assertSame(['tenant_ref' => null, 'user_ref' => null], $child->getValues());
		self::assertSame(['tenant_ref' => null, 'user_ref' => null], $child->getDirtyValues());
	}

	public function testRemovedChildOnNonNullableRelationThrows(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => 10]);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'posts'");
		$this->expectExceptionMessage("owner collection 'users'");
		$this->expectExceptionMessage('not nullable');

		$this->planSingleRemove($relation, $owner, $child);
	}

	public function testRemovedChildThatIsNotTrackedThrows(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel(nullable: true);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$collection = ToManyRelationState::full($owner, 'posts', $this->bindingFor($child), [$item]);
		$collection->remove($item);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('child item is not tracked');

		$this->plan($relation, $collection, $this->records($owner, $child), new RepresentationStore());
	}

	public function testPassingNonHasManyRelationThrows(): void
	{
		$registry = new Registry();
		$registry->collection('users')->primaryKey('id')->field('id', 'int')->end()->end();
		$posts = $registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end();
		$relation = $posts->belongsTo('author', 'users')->innerKey('user_id')->outerKey('id');
		self::assertInstanceOf(BelongsToRelation::class, $relation);
		$collection = new ToManyRelationState(RecordState::new($posts, ['user_id' => 10]), 'author', new RepresentationBinding());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a has-many relation');

		(new HasManyPersistencePlanner())->plan(
			new PersistenceContext(
				new RecordStateStore(),
				new RepresentationStore(),
				new ToManyRelationStore(),
				new ToOneRelationStore(),
				new CommandBuffer()
			),
			$relation,
			$collection,
		);
	}

	public function testPlannerDoesNotClearToManyRelationStateChanges(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'posts', $this->bindingFor($child));
		$collection->add($item);

		$this->plan($relation, $collection, $this->records($owner, $child), $this->trackedMap($this->tracked($item, $child)));

		self::assertTrue($collection->hasChanges());
	}

	public function testPlannerDoesNotAddCommandsToCommandBuffer(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$commands = $this->planSingleAdd($relation, $owner, $child);

		self::assertSame([], $commands->getAll());
	}

	public function testPlannerDoesNotMutateOwnerRecordStateValues(): void
	{
		[$relation, $users, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$ownerValues = $owner->getValues();

		$this->planSingleAdd($relation, $owner, $child);

		self::assertSame($ownerValues, $owner->getValues());
		self::assertTrue($owner->isClean());
	}

	private function planSingleAdd(HasManyRelation $relation, RecordState $owner, RecordState $child): CommandBuffer
	{
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, $relation->getName(), $this->bindingFor($child));
		$collection->add($item);

		return $this->plan($relation, $collection, $this->records($owner, $child), $this->trackedMap(
			$this->tracked($item, $child),
		));
	}

	private function planSingleRemove(HasManyRelation $relation, RecordState $owner, RecordState $child): void
	{
		$item = new stdClass();
		$collection = ToManyRelationState::full(
			$owner,
			$relation->getName(),
			$this->bindingFor($child),
			[$item],
		);
		$collection->remove($item);

		$this->plan($relation, $collection, $this->records($owner, $child), $this->trackedMap(
			$this->tracked($item, $child),
		));
	}

	private function plan(
		HasManyRelation $relation,
		ToManyRelationState $collection,
		RecordStateStore $records,
		RepresentationStore $representations,
	): CommandBuffer {
		$commands = new CommandBuffer();
		(new HasManyPersistencePlanner())->plan(
			new PersistenceContext(
				$records,
				$representations,
				new ToManyRelationStore(),
				new ToOneRelationStore(),
				$commands
			),
			$relation,
			$collection,
		);

		return $commands;
	}

	private function tracked(object $representation, RecordState $record): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($this->bindingFor($record), [
				$record->getStateHash() => $record->getRevision(),
			])
		);
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

	private function records(RecordState ...$records): RecordStateStore
	{
		$map = new RecordStateStore();
		foreach ($records as $record) {
			$map->add($record);
		}

		return $map;
	}

	private function trackedMap(RepresentationState ...$RepresentationStates): RepresentationStore
	{
		$map = new RepresentationStore();
		foreach ($RepresentationStates as $tracked) {
			RepresentationStateObjectRegistry::addTo($map, $tracked);
		}

		return $map;
	}

	/**
	 * @return array{0: HasManyRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function singleKeyModel(bool $nullable = false): array
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
		$relation = $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id')->nullable($nullable);

		return [$relation, $users, $posts];
	}

	/**
	 * @return array{0: HasManyRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function customKeyModel(): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('author_uuid', 'string')->end()
			->end();
		$accounts = $registry->collection('accounts')
			->primaryKey('account_uuid')
			->field('account_uuid', 'string')->end();
		$relation = $accounts->hasMany('posts', 'posts')->innerKey('account_uuid')->outerKey('author_uuid');

		return [$relation, $accounts, $posts];
	}

	/**
	 * @return array{0: HasManyRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function compositeKeyModel(bool $nullable = false): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('tenant_ref', 'user_ref')
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end();
		$relation = $users
			->hasMany('posts', 'posts')
			->innerKey(['tenant_id', 'user_id'])
			->outerKey(['tenant_ref', 'user_ref'])
			->nullable($nullable);

		return [$relation, $users, $posts];
	}
}
