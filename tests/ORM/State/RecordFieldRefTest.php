<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;

final class RecordFieldRefTest extends TestCase
{
	public function testExposesCollectionCollectionNameFieldNameAndKey(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);

		self::assertSame($users, $field->getCollection());
		self::assertSame('users', $field->getCollectionName());
		self::assertSame('name', $field->getFieldName());
		self::assertSame($key, $field->getKey());
		self::assertTrue($field->hasKey());
	}

	public function testSupportsMissingKeyForNewRecords(): void
	{
		$field = new RecordFieldRef($this->users(), 'name');

		self::assertFalse($field->hasKey());
		self::assertNull($field->getKey());
		self::assertTrue($field->isTemplate());
		self::assertFalse($field->hasConcreteRecord());
	}

	public function testTemplateCreatesTemplateRef(): void
	{
		$users = $this->users();
		$field = RecordFieldRef::template($users, 'name');

		self::assertSame($users, $field->getCollection());
		self::assertSame('users', $field->getCollectionName());
		self::assertSame('name', $field->getFieldName());
		self::assertFalse($field->hasKey());
		self::assertNull($field->getKey());
		self::assertFalse($field->hasState());
		self::assertTrue($field->isTemplate());
		self::assertFalse($field->hasConcreteRecord());
	}

	public function testTemplateRecordHashThrows(): void
	{
		$field = RecordFieldRef::template($this->users(), 'name');

		$this->expectException(StateException::class);
		$field->getRecordHash();
	}

	public function testForKeyCreatesKeyedRef(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = RecordFieldRef::forKey($key, 'name');

		self::assertSame($users, $field->getCollection());
		self::assertSame($key, $field->getKey());
		self::assertTrue($field->hasKey());
		self::assertFalse($field->hasState());
		self::assertFalse($field->isTemplate());
		self::assertTrue($field->hasConcreteRecord());
		self::assertSame($key->getHash(), $field->getRecordHash());
	}

	public function testGetRecordHashUsesKeyHash(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);

		self::assertSame($key->getHash(), $field->getRecordHash());
	}

	public function testGetRecordHashWithoutKeyThrows(): void
	{
		$field = new RecordFieldRef($this->users(), 'name');

		$this->expectException(StateException::class);
		$field->getRecordHash();
	}

	public function testForStateCreatesStateTargetedRef(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$field = RecordFieldRef::forState($state, 'name');

		self::assertSame($state->getCollection(), $field->getCollection());
		self::assertSame('users', $field->getCollectionName());
		self::assertSame('name', $field->getFieldName());
		self::assertTrue($field->hasState());
		self::assertSame($state, $field->getState());
		self::assertFalse($field->hasKey());
		self::assertNull($field->getKey());
		self::assertFalse($field->isTemplate());
		self::assertTrue($field->hasConcreteRecord());
		self::assertSame($state->getStateHash(), $field->getRecordHash());
	}

	public function testStateTargetedRefHasKeyAfterStateReceivesKey(): void
	{
		$users = $this->users();
		$state = RecordState::new($users, ['id' => 10, 'name' => 'A1']);
		$field = RecordFieldRef::forState($state, 'name');

		$state->markClean($users->getKey(10));

		self::assertTrue($field->hasKey());
		self::assertSame($state->getKey(), $field->getKey());
	}

	public function testGetStateOnNonStateRefThrows(): void
	{
		$field = RecordFieldRef::forKey($this->users()->getKey(10), 'name');

		$this->expectException(StateException::class);
		$field->getState();
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
