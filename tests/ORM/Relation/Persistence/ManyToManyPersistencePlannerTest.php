<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\ExpectedAffectedRows;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\ManyToManyPersistencePlanner;
use ON\Data\ORM\Relation\ToManyRelationState;
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

final class ManyToManyPersistencePlannerTest extends TestCase
{
	use OrmFixture;

	public function testAddedTrackedTargetProducesInsertCommandForThroughCollection(): void
	{
		[$relation, $users, $tags, $through] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		self::assertCount(1, $commands);
		$command = $this->insertCommand($commands[0]);
		self::assertSame($through, $command->getCollection());
		$this->assertInsertResolvesTo($command, ['user_id' => 10, 'tag_id' => 3]);
	}

	public function testRemovedTrackedTargetProducesDeleteCommandForThroughCollection(): void
	{
		[$relation, $users, $tags, $through] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = ToManyRelationState::full($owner, 'tags', $this->bindingFor($target), [$item]);
		$collection->remove($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		self::assertCount(1, $commands);
		$command = $this->deleteCommand($commands[0]);
		self::assertSame($through, $command->getCollection());
		$this->assertDeleteResolvesTo($command, ['user_id' => 10, 'tag_id' => 3]);
		self::assertSame(ExpectedAffectedRows::zeroOrOne()->describe(), $command->getExpectedAffectedRows()->describe());
	}

	public function testAddedAndRemovedCommandsPreserveInsertThenDeleteOrder(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$addOne = RecordState::clean($tags->getKey(1), ['id' => 1]);
		$addTwo = RecordState::clean($tags->getKey(2), ['id' => 2]);
		$removeOne = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$removeTwo = RecordState::clean($tags->getKey(4), ['id' => 4]);
		$addOneItem = new stdClass();
		$addTwoItem = new stdClass();
		$removeOneItem = new stdClass();
		$removeTwoItem = new stdClass();
		$collection = ToManyRelationState::full(
			$owner,
			'tags',
			$this->bindingFor($addOne),
			[$removeOneItem, $removeTwoItem],
		);
		$collection->add($addOneItem);
		$collection->add($addTwoItem);
		$collection->remove($removeOneItem);
		$collection->remove($removeTwoItem);

		$commands = $this->plan(
			$relation,
			$collection,
			$this->records($owner, $addOne, $addTwo, $removeOne, $removeTwo),
			$this->representations(
				$this->tracked($addOneItem, $addOne),
				$this->tracked($addTwoItem, $addTwo),
				$this->tracked($removeOneItem, $removeOne),
				$this->tracked($removeTwoItem, $removeTwo),
			),
		);

		self::assertContainsOnlyInstancesOf(CommandInterface::class, $commands);
		$firstInsert = $this->insertCommand($commands[0]);
		$secondInsert = $this->insertCommand($commands[1]);
		$firstDelete = $this->deleteCommand($commands[2]);
		$secondDelete = $this->deleteCommand($commands[3]);
		$this->assertInsertResolvesTo($firstInsert, ['user_id' => 10, 'tag_id' => 1]);
		$this->assertInsertResolvesTo($secondInsert, ['user_id' => 10, 'tag_id' => 2]);
		$this->assertDeleteResolvesTo($firstDelete, ['user_id' => 10, 'tag_id' => 3]);
		$this->assertDeleteResolvesTo($secondDelete, ['user_id' => 10, 'tag_id' => 4]);
	}

	public function testThroughCommandValuesAreKeyedByThroughFields(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		$command = $this->insertCommand($commands[0]);
		self::assertSame(['user_id', 'tag_id'], array_keys($command->getValues()));
	}

	public function testAddedItemWithGeneratedOwnerKeyCreatesInsertCommandContainingValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['user_id'], $owner, 'id');
		$this->assertValueRefFor($command->getValues()['tag_id'], $target, 'id');
		self::assertTrue($command->getValues()['tag_id']->isResolved());
		self::assertFalse($command->getValues()['user_id']->isResolved());
	}

	public function testAddedItemWithGeneratedTargetKeyCreatesInsertCommandContainingValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::new($tags, ['name' => 'Tag']);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['user_id'], $owner, 'id');
		$this->assertValueRefFor($command->getValues()['tag_id'], $target, 'id');
		self::assertTrue($command->getValues()['user_id']->isResolved());
		self::assertFalse($command->getValues()['tag_id']->isResolved());
	}

	public function testAddedItemWithGeneratedOwnerAndTargetKeysCreatesTwoValueRefs(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['name' => 'Owner']);
		$target = RecordState::new($tags, ['name' => 'Tag']);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['user_id'], $owner, 'id');
		$this->assertValueRefFor($command->getValues()['tag_id'], $target, 'id');
		self::assertFalse($command->getValues()['user_id']->isResolved());
		self::assertFalse($command->getValues()['tag_id']->isResolved());
	}

	public function testCustomThroughFieldNamesWork(): void
	{
		[$relation, $accounts, $roles] = $this->customKeyModel();
		$owner = RecordState::clean($accounts->getKey(10), ['id' => 10]);
		$target = RecordState::clean($roles->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'roles', $this->bindingFor($target));
		$collection->add($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		$command = $this->insertCommand($commands[0]);
		$this->assertInsertResolvesTo($command, ['account_ref' => 10, 'role_ref' => 3]);
	}

	public function testCompositeOwnerAndTargetKeysWork(): void
	{
		[$relation, $users, $roles] = $this->compositeKeyModel();
		$owner = RecordState::clean($users->getKey([5, 10]), ['tenant_id' => 5, 'user_id' => 10]);
		$target = RecordState::clean($roles->getKey([6, 3]), ['tenant_id' => 6, 'role_id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'roles', $this->bindingFor($target));
		$collection->add($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		$command = $this->insertCommand($commands[0]);
		$this->assertInsertResolvesTo($command, [
			'tenant_ref' => 5,
			'user_ref' => 10,
			'role_tenant_ref' => 6,
			'role_ref' => 3,
		]);
	}

	public function testMissingRepresentationStateThrows(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add(new stdClass());

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'tags' target item is not tracked");

		$this->plan($relation, $collection, $this->records($owner, $target), new RepresentationStore());
	}

	public function testRepresentationStateThatCannotResolveToRecordStateThrows(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);
		$binding = new RepresentationBinding($tags);
		$binding->addField(new RepresentationFieldBinding('id', $tags, 'id'));
		$tracked = RepresentationStateObjectRegistry::remember($item, new RepresentationState($binding, []));

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('cannot be resolved to a record state');

		$this->plan($relation, $collection, $this->records($owner, $target), $this->representations($tracked));
	}

	public function testMissingOwnerKeyValueCreatesUnresolvedValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::new($users, []);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['user_id'], $owner, 'id');
		self::assertFalse($command->getValues()['user_id']->isResolved());
	}

	public function testNullOwnerKeyValueCreatesUnresolvedValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::new($users, ['id' => null]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['user_id'], $owner, 'id');
		self::assertFalse($command->getValues()['user_id']->isResolved());
	}

	public function testMissingTargetKeyValueCreatesUnresolvedValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::new($tags, []);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['tag_id'], $target, 'id');
		self::assertFalse($command->getValues()['tag_id']->isResolved());
	}

	public function testNullTargetKeyValueCreatesUnresolvedValueRef(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::new($tags, ['id' => null]);
		$command = $this->planSingleAddAndReturnInsert($relation, $owner, $target);

		$this->assertValueRefFor($command->getValues()['tag_id'], $target, 'id');
		self::assertFalse($command->getValues()['tag_id']->isResolved());
	}

	public function testPassingNonM2MRelationThrows(): void
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')->field('id', 'int')->end()->end();
		$users = $registry->collection('users')->primaryKey('id')->field('id', 'int')->end();
		$relation = $users->hasMany('posts', 'posts')->innerKey('id')->outerKey('id');
		self::assertInstanceOf(HasManyRelation::class, $relation);
		$collection = new ToManyRelationState(RecordState::new($users, ['id' => 10]), 'posts', new RepresentationBinding($users));

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage('must be a many-to-many relation');

		(new ManyToManyPersistencePlanner())->plan(
			new PersistenceContext($this->context(), new CommandBuffer()),
			$relation,
			$collection,
		);
	}

	public function testPlannerDoesNotClearToManyRelationStateChanges(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, 'tags', $this->bindingFor($target));
		$collection->add($item);

		$this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		self::assertTrue($collection->hasChanges());
	}

	public function testPlannerDoesNotMutateOwnerOrTargetRecordStateValues(): void
	{
		[$relation, $users, $tags] = $this->singleKeyModel();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($tags->getKey(3), ['id' => 3]);
		$ownerValues = $owner->getValues();
		$targetValues = $target->getValues();

		$this->planSingleAdd($relation, $owner, $target);

		self::assertSame($ownerValues, $owner->getValues());
		self::assertSame($targetValues, $target->getValues());
		self::assertTrue($owner->isClean());
		self::assertTrue($target->isClean());
	}

	/**
	 * @param array<string, mixed> $expectedValues
	 */
	private function assertInsertResolvesTo(InsertCommand $command, array $expectedValues): void
	{
		(new CommandValueResolver())->assertReady($command);

		self::assertSame($expectedValues, $command->getValues());
	}

	/**
	 * @param array<string, mixed> $expectedValues
	 */
	private function assertDeleteResolvesTo(DeleteCommand $command, array $expectedValues): void
	{
		(new CommandValueResolver())->assertReady($command);

		self::assertSame($expectedValues, $command->getIdentity());
	}

	private function assertValueRefFor(mixed $value, RecordState $record, string $field): void
	{
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($record, $value->getRecord());
		self::assertSame($field, $value->getField());
	}

	private function insertCommand(CommandInterface $command): InsertCommand
	{
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		return $command;
	}

	private function deleteCommand(CommandInterface $command): DeleteCommand
	{
		if (! $command instanceof DeleteCommand) {
			self::fail('Expected a delete command.');
		}

		return $command;
	}

	private function planSingleAdd(M2MRelation $relation, RecordState $owner, RecordState $target): void
	{
		$this->planSingleAddAndReturnInsert($relation, $owner, $target);
	}

	private function planSingleAddAndReturnInsert(M2MRelation $relation, RecordState $owner, RecordState $target): InsertCommand
	{
		$item = new stdClass();
		$collection = new ToManyRelationState($owner, $relation->getName(), $this->bindingFor($target));
		$collection->add($item);

		$commands = $this->plan($relation, $collection, $this->records($owner, $target), $this->representations(
			$this->tracked($item, $target),
		));

		return $this->insertCommand($commands[0]);
	}

	/**
	 * @return list<CommandInterface>
	 */
	private function plan(
		M2MRelation $relation,
		ToManyRelationState $collection,
		RecordStateStore $records,
		RepresentationStore $representations,
	): array {
		$commands = new CommandBuffer();
		(new ManyToManyPersistencePlanner())->plan(
			new PersistenceContext($this->context($representations, $records), $commands),
			$relation,
			$collection,
		);

		return $commands->getAll();
	}

	private function tracked(object $representation, RecordState $record): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding = $this->bindingFor($record), $this->fieldItemsFor($binding, [$record]))
		);
	}

	private function bindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding($record->getCollection());
		$fields = array_unique(array_merge(array_keys($record->getValues()), $record->getCollection()->getPrimaryKey()));
		foreach ($fields as $field) {
			$field = (string) $field;
			$binding->addField(new RepresentationFieldBinding($field, $record->getCollection(), $field));
		}

		return $binding;
	}

	/**
	 * @return array{0: M2MRelation, 1: CollectionInterface, 2: CollectionInterface, 3: CollectionInterface}
	 */
	private function singleKeyModel(): array
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
		$tags = $registry->getCollection('tags');
		$through = $registry->getCollection('user_tag');
		self::assertInstanceOf(M2MRelation::class, $relation);
		self::assertInstanceOf(CollectionInterface::class, $tags);
		self::assertInstanceOf(CollectionInterface::class, $through);

		return [$relation, $users, $tags, $through];
	}

	/**
	 * @return array{0: M2MRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function customKeyModel(): array
	{
		$registry = new Registry();
		$registry->collection('roles')->primaryKey('id')->field('id', 'int')->end()->end();
		$registry->collection('account_role')
			->field('account_ref', 'int')->end()
			->field('role_ref', 'int')->end()
			->end();
		$accounts = $registry->collection('accounts')
			->primaryKey('id')
			->field('id', 'int')->end();
		$relation = $accounts->relation('roles', M2MRelation::class)
			->collection('roles')
			->innerKey('id')
			->outerKey('id')
			->through('account_role')
				->innerKey('account_ref')
				->outerKey('role_ref')
				->end();
		$roles = $registry->getCollection('roles');
		self::assertInstanceOf(M2MRelation::class, $relation);
		self::assertInstanceOf(CollectionInterface::class, $roles);

		return [$relation, $accounts, $roles];
	}

	/**
	 * @return array{0: M2MRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function compositeKeyModel(): array
	{
		$registry = new Registry();
		$registry->collection('roles')
			->primaryKey('tenant_id', 'role_id')
			->field('tenant_id', 'int')->end()
			->field('role_id', 'int')->end()
			->end();
		$registry->collection('user_role')
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end()
			->field('role_tenant_ref', 'int')->end()
			->field('role_ref', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end();
		$relation = $users->relation('roles', M2MRelation::class)
			->collection('roles')
			->innerKey(['tenant_id', 'user_id'])
			->outerKey(['tenant_id', 'role_id'])
			->through('user_role')
				->innerKey(['tenant_ref', 'user_ref'])
				->outerKey(['role_tenant_ref', 'role_ref'])
				->end();
		$roles = $registry->getCollection('roles');
		self::assertInstanceOf(M2MRelation::class, $relation);
		self::assertInstanceOf(CollectionInterface::class, $roles);

		return [$relation, $users, $roles];
	}
}
