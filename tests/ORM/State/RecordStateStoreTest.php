<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RecordStateStoreTest extends TestCase
{
	use OrmFixture;

	public function testAddIndexesKeylessStateByStateHash(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$map = new RecordStateStore();

		$map->add($state);

		self::assertTrue($map->hasStateHash($state->getStateHash()));
		self::assertSame($state, $map->getByStateHash($state->getStateHash()));
		self::assertSame([$state], $map->getAll());
	}

	public function testGetByKeyReturnsNullForKeylessState(): void
	{
		$users = $this->users();
		$map = new RecordStateStore();

		$map->add(RecordState::new($users, ['name' => 'A1']));

		self::assertNull($map->getByKey($users->getKey(10)));
	}

	public function testAddIndexesCleanKeyedStateByStateHashAndKeyHash(): void
	{
		$users = $this->users();
		$state = RecordState::clean($users->getKey(10), ['name' => 'A1']);
		$map = new RecordStateStore();

		$map->add($state);

		self::assertSame($state, $map->getByStateHash($state->getStateHash()));
		self::assertTrue($map->hasKey($users->getKey(10)));
		self::assertSame($state, $map->getByKey($users->getKey(10)));
	}

	public function testHasAndGetRemainKeyAliases(): void
	{
		$users = $this->users();
		$state = RecordState::clean($users->getKey(10), ['name' => 'A1']);
		$map = new RecordStateStore();

		$map->add($state);

		self::assertTrue($map->has($users->getKey(10)));
		self::assertSame($state, $map->get($users->getKey(10)));
	}

	public function testCompositeKeyWorks(): void
	{
		$postUser = $this->postUser();
		$key = $postUser->getKey(['post_id' => 10, 'user_id' => 4]);
		$state = RecordState::clean($key, ['role' => 'author']);
		$map = new RecordStateStore();

		$map->add($state);

		self::assertSame($state, $map->get($postUser->getKey(['user_id' => 4, 'post_id' => 10])));
	}

	public function testDuplicateSameStateIsNoOp(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$map = new RecordStateStore();

		$map->add($state);
		$map->add($state);

		self::assertSame([$state], $map->getAll());
	}

	public function testIndexKeyAfterMarkCleanAliasesSameStateByKey(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['name' => 'A1']);
		$stateHash = $state->getStateHash();
		$key = $users->getKey(10);
		$map = new RecordStateStore();
		$map->add($state);

		$state->markClean($key);
		$map->indexKey($state);

		self::assertSame($stateHash, $state->getStateHash());
		self::assertSame($state, $map->getByStateHash($stateHash));
		self::assertSame($state, $map->getByKey($key));
	}

	public function testGetAllReturnsUniqueStatesForStateAndKeyAliases(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['name' => 'A1']);
		$map = new RecordStateStore();
		$map->add($state);

		$state->markClean($users->getKey(10));
		$map->indexKey($state);

		self::assertSame([$state], $map->getAll());
	}

	public function testDuplicateSameKeyWithDifferentStateThrows(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$existing = RecordState::clean($key, ['name' => 'A1']);
		$duplicate = RecordState::new($users, ['name' => 'A2']);
		$duplicate->markClean($key);
		$map = new RecordStateStore();
		$map->add($existing);

		$this->expectException(StateException::class);
		$map->add($duplicate);
	}

	public function testDuplicateSameStateHashWithDifferentStateThrows(): void
	{
		$users = $this->users();
		$existing = RecordState::new($users, ['name' => 'A1']);
		$duplicate = RecordState::new($users, ['name' => 'A2']);
		$stateHash = new ReflectionProperty(RecordState::class, 'stateHash');
		$stateHash->setValue($duplicate, $existing->getStateHash());
		$map = new RecordStateStore();
		$map->add($existing);

		$this->expectException(StateException::class);
		$map->add($duplicate);
	}

	public function testGetFromRepresentationReturnsNullWhenNoFieldItemsAttached(): void
	{
		$tracked = new RepresentationState(new RepresentationBinding($this->users()), []);

		self::assertNull((new RecordStateStore())->getFromRepresentation($tracked));
	}

	public function testGetFromRepresentationReturnsRecordWhenFieldItemsResolveToOneRecord(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->trackedFor($state, ['name']);

		self::assertSame($state, (new RecordStateStore())->getFromRepresentation($tracked));
	}

	public function testGetFromRepresentationReturnsSameRecordForMultipleFieldsOnSameRecord(): void
	{
		$state = RecordState::new($this->users(), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->trackedFor($state, ['id', 'name']);

		self::assertSame($state, (new RecordStateStore())->getFromRepresentation($tracked));
	}

	public function testGetFromRepresentationThrowsWhenFieldItemsResolveToDifferentRecords(): void
	{
		$users = $this->users();
		$first = RecordState::new($users, ['name' => 'A1']);
		$second = RecordState::new($users, ['name' => 'A2']);

		$binding = new RepresentationBinding($users);
		$firstField = new RepresentationFieldBinding('first', $users, 'name');
		$secondField = new RepresentationFieldBinding('second', $users, 'name');
		$binding->addField($firstField);
		$binding->addField($secondField);
		$tracked = new RepresentationState($binding, [
			new RepresentationFieldStateItem($firstField, $first, 'name', $first->getRevision()),
			new RepresentationFieldStateItem($secondField, $second, 'name', $second->getRevision()),
		]);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('cannot be collapsed to one record');

		(new RecordStateStore())->getFromRepresentation($tracked);
	}

	public function testGetFromRepresentationCollapsesFieldAndRelationItemsToOneRecord(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['id' => 10, 'name' => 'A1']);

		$binding = new RepresentationBinding($users);
		$nameField = new RepresentationFieldBinding('name', $users, 'name');
		$binding->addField($nameField);
		$relationBinding = new RepresentationRelationBinding('posts', $users, 'posts', new RepresentationBinding($this->posts()));
		$binding->addRelation($relationBinding);

		$tracked = new RepresentationState(
			$binding,
			[new RepresentationFieldStateItem($nameField, $state, 'name', $state->getRevision())],
			[new RepresentationRelationStateItem($relationBinding, $state, 'posts')],
		);

		self::assertSame($state, (new RecordStateStore())->getFromRepresentation($tracked));
	}

	public function testRemoveOnlyRemovesKeyAlias(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$state = RecordState::clean($key, ['name' => 'A1']);
		$map = new RecordStateStore();
		$map->add($state);

		$map->remove($key);

		self::assertNull($map->getByKey($key));
		self::assertSame($state, $map->getByStateHash($state->getStateHash()));
	}

	public function testRemoveStateRemovesStateHashAndKeyHashIndexes(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$state = RecordState::clean($key, ['name' => 'A1']);
		$map = new RecordStateStore();
		$map->add($state);

		$map->removeState($state);

		self::assertNull($map->getByStateHash($state->getStateHash()));
		self::assertNull($map->getByKey($key));
		self::assertSame([], $map->getAll());
	}

	public function testClearClearsStateHashAndKeyHashIndexes(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$state = RecordState::clean($key, ['name' => 'A1']);
		$map = new RecordStateStore();
		$map->add($state);

		$map->clear();

		self::assertNull($map->getByStateHash($state->getStateHash()));
		self::assertNull($map->getByKey($key));
		self::assertSame([], $map->getAll());
	}

	private function postUser(): CollectionInterface
	{
		return (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->end()
			->field('user_id', 'int')->end();
	}

	/**
	 * @param list<string> $fieldNames
	 */
	private function trackedFor(RecordState $record, array $fieldNames): RepresentationState
	{
		$binding = new RepresentationBinding($record->getCollection());
		$items = [];
		foreach ($fieldNames as $fieldName) {
			$fieldBinding = new RepresentationFieldBinding($fieldName, $record->getCollection(), $fieldName);
			$binding->addField($fieldBinding);
			$items[] = new RepresentationFieldStateItem($fieldBinding, $record, $fieldName, $record->getRevision());
		}

		return new RepresentationState($binding, $items);
	}
}
