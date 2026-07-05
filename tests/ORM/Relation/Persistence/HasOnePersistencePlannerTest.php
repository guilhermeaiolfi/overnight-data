<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\HasOnePersistencePlanner;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionStore;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceStore;
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

final class HasOnePersistencePlannerTest extends TestCase
{
	public function testSetTrackedTargetCopiesOwnerKeyIntoTargetOuterKey(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => null]);

		$this->planSet($relation, $owner, $target);

		self::assertSame(10, $target->getValue('user_id'));
	}

	public function testSetTrackedTargetWithCustomKeyNamesWorks(): void
	{
		[$relation, $accounts, $profiles] = $this->customKeyModel();
		$owner = RecordState::clean($accounts->getKey('account-1'), ['account_uuid' => 'account-1']);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'owner_uuid' => null]);

		$this->planSet($relation, $owner, $target);

		self::assertSame('account-1', $target->getValue('owner_uuid'));
	}

	public function testSetTrackedTargetWithCompositeKeysWorks(): void
	{
		[$relation, $users, $profiles] = $this->compositeKeyModel();
		$owner = RecordState::clean($users->getKey([7, 10]), ['tenant_id' => 7, 'user_id' => 10]);
		$target = RecordState::clean($profiles->getKey([1, 2]), ['tenant_ref' => null, 'user_ref' => null]);

		$this->planSet($relation, $owner, $target);

		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10], $target->getValues());
	}

	public function testSetTargetThatIsNewIsAllowedWhenOwnerKeyValuesAreAvailable(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['id' => 10]);
		$target = RecordState::new($profiles, ['id' => 5, 'label' => 'Draft']);

		$this->planSet($relation, $owner, $target);

		self::assertTrue($target->isNew());
		self::assertSame(['id' => 5, 'label' => 'Draft', 'user_id' => 10], $target->getValues());
	}

	public function testSetTargetMakesCleanTargetDirtyWhenForeignKeyChanges(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => 8]);

		$this->planSet($relation, $owner, $target);

		self::assertTrue($target->isDirty());
		self::assertSame(['user_id' => 10], $target->getDirtyValues());
	}

	public function testMissingOwnerKeyValueWritesValueRef(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::new($users, []);
		$target = RecordState::new($profiles, ['id' => 5]);

		$this->planSet($relation, $owner, $target);

		$value = $target->getValue('user_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($owner, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testNullOwnerKeyValueWritesValueRef(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['id' => null]);
		$target = RecordState::new($profiles, ['id' => 5]);

		$this->planSet($relation, $owner, $target);

		$value = $target->getValue('user_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($owner, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testMissingTrackedCurrentTargetThrows(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5]);
		$reference = new RelatedReference($owner, 'profile', $this->bindingFor($target));
		$reference->set(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'profile' target item is not tracked");

		$this->plan($relation, $reference, $this->records($owner, $target), new RepresentationStore());
	}

	public function testTrackedCurrentTargetThatCannotResolveToRecordStateThrows(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$reference = new RelatedReference($owner, 'profile', $this->bindingFor($target));
		$reference->set($item);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($profiles, 'id')));
		$tracked = RepresentationStateObjectRegistry::remember($item, new RepresentationState($binding, []));

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('cannot be resolved to a record state');

		$this->plan($relation, $reference, $this->records($owner, $target), $this->trackedMap($tracked));
	}

	public function testClearNullableHasOneNullsBaselineTargetOuterKeyFields(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel(nullable: true);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$baseline = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => 10]);

		$this->planClear($relation, $owner, $baseline);

		self::assertNull($baseline->getValue('user_id'));
		self::assertSame(['user_id' => null], $baseline->getDirtyValues());
	}

	public function testClearNonNullableHasOneThrows(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$baseline = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => 10]);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'profile'");
		$this->expectExceptionMessage("owner collection 'users'");
		$this->expectExceptionMessage('not nullable');

		$this->planClear($relation, $owner, $baseline);
	}

	public function testReplacementNullableNullsOldTargetAndSetsNewTargetOuterKey(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel(nullable: true);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$oldTarget = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => 10]);
		$newTarget = RecordState::clean($profiles->getKey(6), ['id' => 6, 'user_id' => null]);

		$this->planReplacement($relation, $owner, $oldTarget, $newTarget);

		self::assertNull($oldTarget->getValue('user_id'));
		self::assertSame(10, $newTarget->getValue('user_id'));
	}

	public function testReplacementNonNullableThrowsBeforePartialMutation(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$oldTarget = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => 10]);
		$newTarget = RecordState::clean($profiles->getKey(6), ['id' => 6, 'user_id' => null]);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('not nullable');

		try {
			$this->planReplacement($relation, $owner, $oldTarget, $newTarget);
		} finally {
			self::assertSame(10, $oldTarget->getValue('user_id'));
			self::assertNull($newTarget->getValue('user_id'));
			self::assertTrue($oldTarget->isClean());
			self::assertTrue($newTarget->isClean());
		}
	}

	public function testPassingBelongsToRelationThrows(): void
	{
		$registry = new Registry();
		$registry->collection('users')->primaryKey('id')->field('id', 'int')->end()->end();
		$posts = $registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('user_id', 'int')->end();
		$relation = $posts->belongsTo('author', 'users')->innerKey('user_id')->outerKey('id');
		self::assertInstanceOf(BelongsToRelation::class, $relation);
		$reference = new RelatedReference(RecordState::new($posts, ['user_id' => null]), 'author', new RepresentationBinding());
		$reference->set(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a has-one relation');

		(new HasOnePersistencePlanner())->plan(
			new PersistenceContext(
				new RecordStateStore(),
				new RepresentationStore(),
				new RelatedCollectionStore(),
				new RelatedReferenceStore(),
				new CommandBuffer()
			),
			$relation,
			$reference,
		);
	}

	public function testPassingRelatedCollectionChangeThrows(): void
	{
		[$relation, $users] = $this->singleKeyModel();
		$collection = new RelatedCollection(RecordState::new($users, ['id' => 10]), 'profile', new RepresentationBinding());
		$collection->add(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a related reference');

		(new HasOnePersistencePlanner())->plan(
			new PersistenceContext(
				new RecordStateStore(),
				new RepresentationStore(),
				new RelatedCollectionStore(),
				new RelatedReferenceStore(),
				new CommandBuffer()
			),
			$relation,
			$collection,
		);
	}

	public function testPlannerDoesNotClearRelatedReferenceChanges(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => null]);
		$reference = $this->changedReference($relation, $owner, $target);
		$targetObject = $reference->getTarget();
		self::assertNotNull($targetObject);

		$this->plan($relation, $reference, $this->records($owner, $target), $this->trackedMap($this->tracked($targetObject, $target)));

		self::assertTrue($reference->hasChanges());
	}

	public function testPlannerDoesNotAddCommandsToCommandBuffer(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => null]);

		$commands = $this->planSet($relation, $owner, $target);

		self::assertSame([], $commands->getAll());
	}

	public function testPlannerDoesNotMutateOwnerRecordStateValues(): void
	{
		[$relation, $users, $profiles] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$target = RecordState::clean($profiles->getKey(5), ['id' => 5, 'user_id' => null]);
		$ownerValues = $owner->getValues();

		$this->planSet($relation, $owner, $target);

		self::assertSame($ownerValues, $owner->getValues());
		self::assertTrue($owner->isClean());
	}

	private function planSet(HasOneRelation $relation, RecordState $owner, RecordState $target): CommandBuffer
	{
		$reference = $this->changedReference($relation, $owner, $target);
		$targetObject = $reference->getTarget();
		self::assertNotNull($targetObject);

		return $this->plan($relation, $reference, $this->records($owner, $target), $this->trackedMap(
			$this->tracked($targetObject, $target),
		));
	}

	private function planClear(HasOneRelation $relation, RecordState $owner, RecordState $baseline): void
	{
		$baselineObject = new stdClass();
		$reference = new RelatedReference($owner, $relation->getName(), $this->bindingFor($baseline), $baselineObject);
		$reference->clear();

		$this->plan($relation, $reference, $this->records($owner, $baseline), $this->trackedMap(
			$this->tracked($baselineObject, $baseline),
		));
	}

	private function planReplacement(
		HasOneRelation $relation,
		RecordState $owner,
		RecordState $oldTarget,
		RecordState $newTarget,
	): void {
		$oldObject = new stdClass();
		$newObject = new stdClass();
		$reference = new RelatedReference($owner, $relation->getName(), $this->bindingFor($newTarget), $oldObject);
		$reference->set($newObject);

		$this->plan($relation, $reference, $this->records($owner, $oldTarget, $newTarget), $this->trackedMap(
			$this->tracked($oldObject, $oldTarget),
			$this->tracked($newObject, $newTarget),
		));
	}

	private function changedReference(HasOneRelation $relation, RecordState $owner, RecordState $target): RelatedReference
	{
		$targetObject = new stdClass();
		$reference = new RelatedReference($owner, $relation->getName(), $this->bindingFor($target));
		$reference->set($targetObject);

		return $reference;
	}

	private function plan(
		HasOneRelation $relation,
		RelatedReference $reference,
		RecordStateStore $records,
		RepresentationStore $representations,
	): CommandBuffer {
		$commands = new CommandBuffer();
		(new HasOnePersistencePlanner())->plan(
			new PersistenceContext(
				$records,
				$representations,
				new RelatedCollectionStore(),
				new RelatedReferenceStore(),
				$commands
			),
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
	 * @return array{0: HasOneRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function singleKeyModel(bool $nullable = false): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->field('user_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$relation = $users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id')->nullable($nullable);

		return [$relation, $users, $profiles];
	}

	/**
	 * @return array{0: HasOneRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function customKeyModel(): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('owner_uuid', 'string')->end()
			->end();
		$accounts = $registry->collection('accounts')
			->primaryKey('account_uuid')
			->field('account_uuid', 'string')->end();
		$relation = $accounts->hasOne('profile', 'profiles')->innerKey('account_uuid')->outerKey('owner_uuid');

		return [$relation, $accounts, $profiles];
	}

	/**
	 * @return array{0: HasOneRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function compositeKeyModel(): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->primaryKey('tenant_ref', 'user_ref')
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end();
		$relation = $users
			->hasOne('profile', 'profiles')
			->innerKey(['tenant_id', 'user_id'])
			->outerKey(['tenant_ref', 'user_ref']);

		return [$relation, $users, $profiles];
	}
}
