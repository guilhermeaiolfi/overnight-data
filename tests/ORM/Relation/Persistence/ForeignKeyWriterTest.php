<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Relation\Persistence\ForeignKeyWriter;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\ValueRef;
use PHPUnit\Framework\TestCase;

final class ForeignKeyWriterTest extends TestCase
{
	public function testCopyValuesCopiesMappedValueRefsFromSourceToTarget(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();

		$source = RecordState::new($users, []);
		$target = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => null]);

		(new ForeignKeyWriter())->copyValues(
			'posts',
			['id'],
			['user_id'],
			$source,
			$target,
			$this->missingTargetFieldFactory('outer'),
		);

		$value = $target->getValue('user_id');
		self::assertInstanceOf(ValueRef::class, $value);
		self::assertSame($source, $value->getRecord());
		self::assertSame('id', $value->getField());
	}

	public function testNullValuesNullsTargetFields(): void
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();
		$target = RecordState::clean($posts->getKey(5), ['id' => 5, 'user_id' => 10]);

		(new ForeignKeyWriter())->nullValues($target, ['user_id']);

		self::assertNull($target->getValue('user_id'));
	}

	public function testBuildValuesReturnsMappedValuesArray(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();

		$source = RecordState::clean($users->getKey(10), ['id' => 10]);

		$values = (new ForeignKeyWriter())->buildValues(
			'posts',
			['id'],
			['user_id'],
			$source,
			$this->missingTargetFieldFactory('through'),
		);

		self::assertArrayHasKey('user_id', $values);
		self::assertInstanceOf(ValueRef::class, $values['user_id']);
		self::assertSame($source, $values['user_id']->getRecord());
		self::assertSame('id', $values['user_id']->getField());
	}

	public function testThrowsCallerCreatedMissingTargetFieldExceptionWhenMappingIsIncomplete(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();

		$source = RecordState::clean($users->getKey(10), ['id' => 10]);
		$target = RecordState::clean($posts->getKey(5), ['id' => 5]);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'posts' is missing an outer key field for owner collection 'users' field 'id'.");

		(new ForeignKeyWriter())->copyValues(
			'posts',
			['id'],
			[],
			$source,
			$target,
			$this->missingTargetFieldFactory('outer'),
		);
	}

	/**
	 * @return callable(string $relationName, string $sourceField, int|string $index): RelationPersistenceException
	 */
	private function missingTargetFieldFactory(string $kind): callable
	{
		return static function (string $relationName, string $sourceField, int|string $index) use ($kind): RelationPersistenceException {
			if ($kind === 'through') {
				return new RelationPersistenceException(sprintf(
					"Relation '%s' through mapping is missing a target field for owner field '%s'.",
					$relationName,
					$sourceField,
				));
			}

			return new RelationPersistenceException(sprintf(
				"Relation '%s' is missing an outer key field for owner collection 'users' field '%s'.",
				$relationName,
				$sourceField,
			));
		};
	}
}
