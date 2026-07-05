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
use ON\Data\ORM\Relation\Persistence\BelongsToPersistencePlanner;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
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
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class BelongsToPersistencePlannerTest extends TestCase
{
	use OrmFixture;

	public function testSetTrackedTargetCopiesTargetKeyIntoOwnerInnerKey(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);

		$this->planSet($relation, $owner, $target);

		self::assertSame(10, $owner->getValue('author_id'));
	}

	public function testSetTrackedTargetWithCustomKeyNamesWorks(): void
	{
		[$relation, $posts, $accounts] = $this->customKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_uuid' => null]);
		$target = RecordState::clean($accounts->getKey('account-1'), ['uuid' => 'account-1']);

		$this->planSet($relation, $owner, $target);

		self::assertSame('account-1', $owner->getValue('author_uuid'));
	}

	public function testSetTrackedTargetWithCompositeKeysWorks(): void
	{
		[$relation, $posts, $users] = $this->compositeKeyModel();
		$owner = RecordState::clean($posts->getKey([1, 2]), ['tenant_ref' => null, 'author_ref' => null]);
		$target = RecordState::clean($users->getKey([7, 10]), ['tenant_id' => 7, 'user_id' => 10]);

		$this->planSet($relation, $owner, $target);

		self::assertSame(['tenant_ref' => 7, 'author_ref' => 10], $owner->getValues());
	}

	public function testSetTargetThatIsNewIsAllowedWhenTargetKeyValuesAreAvailable(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::new($posts, ['id' => 5, 'author_id' => null]);
		$target = RecordState::new($users, ['id' => 10, 'name' => 'Ada']);

		$this->planSet($relation, $owner, $target);

		self::assertTrue($target->isNew());
		self::assertSame(10, $owner->getValue('author_id'));
	}

	public function testSetTargetMakesCleanOwnerDirtyWhenForeignKeyChanges(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => 8]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);

		$this->planSet($relation, $owner, $target);

		self::assertTrue($owner->isDirty());
		self::assertSame(['author_id' => 10], $owner->getDirtyValues());
	}

	public function testSetTargetWithSameForeignKeyDoesNotCreateDirtyValues(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => 10]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);

		$this->planSet($relation, $owner, $target);

		self::assertTrue($owner->isClean());
		self::assertSame([], $owner->getDirtyValues());
	}

	public function testMissingTrackedTargetRepresentationThrows(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);
		$reference = new ToOneRelationState($owner, 'author', $this->bindingFor($target));
		$reference->set(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'author' target item is not tracked");

		$this->plan($relation, $reference, $this->records($owner, $target), new RepresentationStore());
	}

	public function testTrackedTargetRepresentationThatCannotResolveToRecordStateThrows(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);
		$item = new stdClass();
		$reference = new ToOneRelationState($owner, 'author', $this->bindingFor($target));
		$reference->set($item);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($users, 'id')));
		$tracked = RepresentationStateObjectRegistry::remember($item, new RepresentationState($binding, []));

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('cannot be resolved to a record state');

		$this->plan($relation, $reference, $this->records($owner, $target), $this->representations($tracked));
	}

	public function testMissingTargetKeyValueWritesValueRef(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::new($posts, ['author_id' => null]);
		$target = RecordState::new($users, ['name' => 'Ada']);

		$this->planSet($relation, $owner, $target);

		$value = $owner->getValue('author_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($target, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testNullTargetKeyValueWritesValueRef(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::new($posts, ['author_id' => null]);
		$target = RecordState::new($users, ['id' => null]);

		$this->planSet($relation, $owner, $target);

		$value = $owner->getValue('author_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($target, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testClearNullableBelongsToSetsOwnerInnerKeyFieldsToNull(): void
	{
		[$relation, $posts] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => 10]);

		$this->planClear($relation, $owner);

		self::assertNull($owner->getValue('author_id'));
		self::assertSame(['author_id' => null], $owner->getDirtyValues());
	}

	public function testClearNullableCompositeBelongsToSetsAllOwnerInnerKeyFieldsToNull(): void
	{
		[$relation, $posts] = $this->compositeKeyModel();
		$owner = RecordState::clean($posts->getKey([7, 10]), ['tenant_ref' => 7, 'author_ref' => 10]);

		$this->planClear($relation, $owner);

		self::assertSame(['tenant_ref' => null, 'author_ref' => null], $owner->getValues());
		self::assertSame(['tenant_ref' => null, 'author_ref' => null], $owner->getDirtyValues());
	}

	public function testClearNonNullableBelongsToThrows(): void
	{
		[$relation, $posts] = $this->singleKeyModel(nullable: false);
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => 10]);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'author'");
		$this->expectExceptionMessage("owner collection 'posts'");
		$this->expectExceptionMessage('not nullable');

		$this->planClear($relation, $owner);
	}

	public function testPassingNonBelongsToRelationThrows(): void
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end()->end();
		$users = $registry->collection('users')->primaryKey('id')->field('id', 'int')->end();
		$relation = $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');
		self::assertInstanceOf(HasManyRelation::class, $relation);
		$reference = new ToOneRelationState(RecordState::new($users, ['id' => 10]), 'posts', new RepresentationBinding());
		$reference->set(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a belongs-to relation');

		(new BelongsToPersistencePlanner())->plan(
			new PersistenceContext($this->context(), new CommandBuffer()),
			$relation,
			$reference,
		);
	}

	public function testPassingToManyRelationStateChangeThrows(): void
	{
		[$relation, $posts] = $this->singleKeyModel();
		$collection = new ToManyRelationState(RecordState::new($posts, ['author_id' => 10]), 'author', new RepresentationBinding());
		$collection->add(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a related reference');

		(new BelongsToPersistencePlanner())->plan(
			new PersistenceContext($this->context(), new CommandBuffer()),
			$relation,
			$collection,
		);
	}

	public function testPlannerDoesNotClearToOneRelationStateChanges(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);
		$reference = $this->changedReference($relation, $owner, $target);
		$targetObject = $reference->getTarget();
		self::assertNotNull($targetObject);

		$this->plan($relation, $reference, $this->records($owner, $target), $this->representations($this->tracked($targetObject, $target)));

		self::assertTrue($reference->hasChanges());
	}

	public function testPlannerDoesNotAddCommandsToCommandBuffer(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10]);

		$commands = $this->planSet($relation, $owner, $target);

		self::assertSame([], $commands->getAll());
	}

	public function testPlannerDoesNotMutateTargetRecordStateValues(): void
	{
		[$relation, $posts, $users] = $this->singleKeyModel();
		$owner = RecordState::clean($posts->getKey(5), ['id' => 5, 'author_id' => null]);
		$target = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$targetValues = $target->getValues();

		$this->planSet($relation, $owner, $target);

		self::assertSame($targetValues, $target->getValues());
		self::assertTrue($target->isClean());
	}

	private function planSet(BelongsToRelation $relation, RecordState $owner, RecordState $target): CommandBuffer
	{
		$reference = $this->changedReference($relation, $owner, $target);
		$targetObject = $reference->getTarget();
		self::assertNotNull($targetObject);

		return $this->plan($relation, $reference, $this->records($owner, $target), $this->representations(
			$this->tracked($targetObject, $target),
		));
	}

	private function planClear(BelongsToRelation $relation, RecordState $owner): void
	{
		$baseline = new stdClass();
		$reference = new ToOneRelationState($owner, $relation->getName(), new RepresentationBinding(), $baseline);
		$reference->clear();

		$this->plan($relation, $reference, $this->records($owner), new RepresentationStore());
	}

	private function changedReference(BelongsToRelation $relation, RecordState $owner, RecordState $target): ToOneRelationState
	{
		$targetObject = new stdClass();
		$reference = new ToOneRelationState($owner, $relation->getName(), $this->bindingFor($target));
		$reference->set($targetObject);

		return $reference;
	}

	private function plan(
		BelongsToRelation $relation,
		ToOneRelationState $reference,
		RecordStateStore $records,
		RepresentationStore $representations,
	): CommandBuffer {
		$commands = new CommandBuffer();
		(new BelongsToPersistencePlanner())->plan(
			new PersistenceContext($this->context($representations, $records), $commands),
			$relation,
			$reference,
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

	/**
	 * @return array{0: BelongsToRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function singleKeyModel(bool $nullable = true): array
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('author_id', 'int')->end();
		$relation = $posts->belongsTo('author', 'users')->innerKey('author_id')->outerKey('id')->nullable($nullable);

		return [$relation, $posts, $users];
	}

	/**
	 * @return array{0: BelongsToRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function customKeyModel(): array
	{
		$registry = new Registry();
		$accounts = $registry->collection('accounts')
			->primaryKey('uuid')
			->field('uuid', 'string')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('author_uuid', 'string')->end();
		$relation = $posts->belongsTo('author', 'accounts')->innerKey('author_uuid')->outerKey('uuid');

		return [$relation, $posts, $accounts];
	}

	/**
	 * @return array{0: BelongsToRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function compositeKeyModel(): array
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end();
		$posts = $registry->collection('posts')
			->primaryKey('tenant_ref', 'author_ref')
			->field('tenant_ref', 'int')->end()
			->field('author_ref', 'int')->end();
		$relation = $posts
			->belongsTo('author', 'users')
			->innerKey(['tenant_ref', 'author_ref'])
			->outerKey(['tenant_id', 'user_id']);

		return [$relation, $posts, $users];
	}
}
