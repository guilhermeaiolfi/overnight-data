<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
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

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
