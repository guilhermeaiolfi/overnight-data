<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use PHPUnit\Framework\TestCase;

final class RecordStateMapTest extends TestCase
{
	public function testAddAndGetByExistingKey(): void
	{
		$users = $this->users();
		$state = RecordState::clean($users->getKey(10), ['name' => 'A1']);
		$map = new RecordStateMap();

		$map->add($state);

		self::assertTrue($map->has($users->getKey(10)));
		self::assertSame($state, $map->get($users->getKey(10)));
		self::assertSame([$state], $map->getAll());
	}

	public function testCompositeKeyWorks(): void
	{
		$postUser = $this->postUser();
		$key = $postUser->getKey(['post_id' => 10, 'user_id' => 4]);
		$state = RecordState::clean($key, ['role' => 'author']);
		$map = new RecordStateMap();

		$map->add($state);

		self::assertSame($state, $map->get($postUser->getKey(['user_id' => 4, 'post_id' => 10])));
	}

	public function testDuplicateSameKeyWithSameStateIsNoOp(): void
	{
		$state = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$map = new RecordStateMap();

		$map->add($state);
		$map->add($state);

		self::assertSame([$state], $map->getAll());
	}

	public function testDuplicateSameKeyWithDifferentStateThrows(): void
	{
		$users = $this->users();
		$map = new RecordStateMap();
		$map->add(RecordState::clean($users->getKey(10), ['name' => 'A1']));

		$this->expectException(StateException::class);
		$map->add(RecordState::clean($users->getKey(10), ['name' => 'A2']));
	}

	public function testStateWithoutKeyCannotBeAdded(): void
	{
		$map = new RecordStateMap();

		$this->expectException(StateException::class);
		$map->add(RecordState::new($this->users(), ['name' => 'A1']));
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}

	private function postUser(): CollectionInterface
	{
		return (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->end()
			->field('user_id', 'int')->end();
	}
}
