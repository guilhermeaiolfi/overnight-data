<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\View\ViewDefinition;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomViewRelation;

final class DefinitionMemberNameConflictTest extends TestCase
{
	public function testRelationFollowedByFieldIsRejectedWithoutMutatingStorage(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->relation('posts', HasManyRelation::class);

		try {
			$users->field('posts');
			self::fail('Expected field/relation name conflict.');
		} catch (DefinitionNameConflictException $exception) {
			self::assertStringContainsString("Definition 'users' member name 'posts' is already used by a relation.", $exception->getMessage());
		}

		self::assertTrue($users->hasRelation('posts'));
		self::assertFalse($users->hasField('posts'));
		self::assertArrayNotHasKey('posts', $registry->all()['collections']['users']['fields']);
	}

	public function testFieldFollowedByRelationIsRejectedWithoutMutatingStorage(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('posts', 'string');

		try {
			$users->relation('posts', HasManyRelation::class);
			self::fail('Expected field/relation name conflict.');
		} catch (DefinitionNameConflictException $exception) {
			self::assertStringContainsString("Definition 'users' member name 'posts' is already used by a field.", $exception->getMessage());
		}

		self::assertTrue($users->hasField('posts'));
		self::assertFalse($users->hasRelation('posts'));
		self::assertArrayNotHasKey('posts', $registry->all()['collections']['users']['relations']);
	}

	public function testDirectFieldMapMutationPathHonorsRelationConflict(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->relation('posts', HasManyRelation::class);

		$this->expectException(DefinitionNameConflictException::class);
		$this->expectExceptionMessage("Definition 'users' member name 'posts' is already used by a relation.");
		$users->getFields()->createOrReturn('posts', Field::class);
	}

	public function testDirectRelationMapMutationPathHonorsFieldConflict(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('posts', 'string');

		$this->expectException(DefinitionNameConflictException::class);
		$this->expectExceptionMessage("Definition 'users' member name 'posts' is already used by a field.");
		$users->getRelations()->createOrReturn('posts', HasManyRelation::class);
	}

	public function testRepeatedValidFieldLookupPreservesWrapperIdentity(): void
	{
		$users = (new Registry())->collection('users');

		$field = $users->field('name', 'string');

		self::assertSame($field, $users->field('name'));
		self::assertSame($field, $users->getFields()->get('name'));
	}

	public function testRepeatedValidRelationLookupPreservesWrapperIdentityAndClassChecks(): void
	{
		$users = (new Registry())->collection('users');

		$relation = $users->relation('posts', HasManyRelation::class);

		self::assertSame($relation, $users->relation('posts', HasManyRelation::class));
		self::assertSame($relation, $users->getRelations()->get('posts'));
		self::assertInstanceOf(HasManyRelation::class, $relation);
	}

	public function testRestoredCollectionCollisionIsRejectedOnWrapperRestore(): void
	{
		$registry = new Registry([
			'collections' => [
				'users' => [
					'class' => Collection::class,
					'table' => 'users',
					'fields' => [
						'posts' => ['class' => Field::class, 'type' => 'string'],
					],
					'relations' => [
						'posts' => ['class' => HasManyRelation::class],
					],
					'metadata' => [],
					'primaryKey' => [],
				],
			],
			'views' => [],
		]);

		$this->expectException(DefinitionNameConflictException::class);
		$this->expectExceptionMessage("Definition 'users' member name 'posts' is used by both a field and a relation.");
		$registry->getCollection('users');
	}

	public function testRestoredViewCollisionIsRejectedOnWrapperRestore(): void
	{
		$registry = new Registry([
			'collections' => [],
			'views' => [
				'users' => [
					'class' => ViewDefinition::class,
					'source' => null,
					'fields' => [
						'posts' => ['class' => Field::class, 'type' => 'string'],
					],
					'relations' => [
						'posts' => ['class' => CustomViewRelation::class],
					],
					'metadata' => [],
				],
			],
		]);

		$this->expectException(DefinitionNameConflictException::class);
		$this->expectExceptionMessage("Definition 'users' member name 'posts' is used by both a field and a relation.");
		$registry->getView('users');
	}

	public function testSameNameInSeparateDefinitionsRemainsValid(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('posts', 'string');
		$registry->collection('articles')->relation('posts', HasManyRelation::class);

		self::assertTrue($registry->getCollection('users')?->hasField('posts'));
		self::assertTrue($registry->getCollection('articles')?->hasRelation('posts'));
	}

	public function testRejectedMutationLeavesExportCanonical(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('posts', 'string');
		$before = $registry->all();

		try {
			$users->relation('posts', HasManyRelation::class);
			self::fail('Expected field/relation name conflict.');
		} catch (DefinitionNameConflictException) {
			self::assertSame($before, $registry->all());
			self::assertSame(Field::class, $registry->all()['collections']['users']['fields']['posts']['class']);
			self::assertSame('string', $registry->all()['collections']['users']['fields']['posts']['type']);
			self::assertArrayNotHasKey('posts', $registry->all()['collections']['users']['relations']);
		}
	}
}
