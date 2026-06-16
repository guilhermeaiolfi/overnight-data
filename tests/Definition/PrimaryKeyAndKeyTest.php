<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Exception\CompositeKeyException;
use ON\Data\Definition\Exception\ConflictingPrimaryKeyDefinitionException;
use ON\Data\Definition\Exception\InvalidPrimaryKeyException;
use ON\Data\Definition\Exception\PrimaryKeyNotDefinedException;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyAndKeyTest extends TestCase
{
	public function testCollectionPrimaryKeyIsStoredAtCollectionLevelInCanonicalOrder(): void
	{
		$collection = (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->column('post_id')->end()
			->field('user_id', 'int')->column('user_id')->end();

		self::assertTrue($collection->hasPrimaryKey());
		self::assertSame(['post_id', 'user_id'], $collection->getPrimaryKey());
		self::assertSame(['post_id', 'user_id'], $collection->getPrimaryKeyColumns());
		self::assertTrue($collection->isCompositePrimaryKey());
		self::assertSame(
			['post_id', 'user_id'],
			array_map(static fn ($field) => $field->getName(), $collection->getPrimaryKeyFields())
		);
		self::assertArrayNotHasKey('pk', $collection->all()['fields']['post_id']);
	}

	public function testCollectionPrimaryKeyCanBeDeclaredBeforeFieldsAndReplaced(): void
	{
		$collection = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('uuid', 'string')->end();

		self::assertTrue($collection->fields->get('id')->isPrimaryKey());
		self::assertFalse($collection->fields->get('uuid')->isPrimaryKey());

		$collection->primaryKey('uuid');

		self::assertSame(['uuid'], $collection->getPrimaryKey());
		self::assertFalse($collection->fields->get('id')->isPrimaryKey());
		self::assertTrue($collection->fields->get('uuid')->isPrimaryKey());
	}

	public function testPrimaryKeyValidationRejectsInvalidDefinitionsAndMissingKeys(): void
	{
		$collection = (new Registry())->collection('users');

		$this->expectException(InvalidPrimaryKeyException::class);
		$collection->primaryKey();
	}

	public function testWhitespaceAndDuplicatePrimaryKeyDefinitionsAreRejected(): void
	{
		$collection = (new Registry())->collection('users');

		try {
			$collection->primaryKey(' ');
			self::fail('Expected whitespace-only primary key field names to be rejected.');
		} catch (InvalidPrimaryKeyException) {
		}

		$this->expectException(InvalidPrimaryKeyException::class);
		$collection->primaryKey('id', 'id');
	}

	public function testMissingPrimaryKeyOperationsThrowFocusedException(): void
	{
		$collection = (new Registry())->collection('users')->field('id', 'int')->end();

		self::assertFalse($collection->hasPrimaryKey());

		$this->expectException(PrimaryKeyNotDefinedException::class);
		$collection->getPrimaryKey();
	}

	public function testKeySupportsScalarAssociativeAndPositionalInputs(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->end();

		self::assertSame(['id' => 10], $users->getKey(10)->getValues());
		self::assertSame(['id' => 11], $users->getKey(['id' => 11])->getValues());
		self::assertSame(['id' => 12], $users->getKey(['user_id' => 12])->getValues());
		self::assertSame(['id' => 13], $users->getKey([13])->getValues());
	}

	public function testCompositeKeyCanonicalizesInputAndAccessors(): void
	{
		$postUser = (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->column('post_ref')->end()
			->field('user_id', 'int')->column('user_ref')->end();

		$key = $postUser->getKey(['user_ref' => 4, 'post_id' => 10]);

		self::assertInstanceOf(Key::class, $key);
		self::assertSame(['post_id' => 10, 'user_id' => 4], $key->getValues());
		self::assertSame(10, $key->getFieldValue('post_id'));
		self::assertTrue($key->isComposite());
		self::assertSame('post_user#post_id=10,user_id=4', $key->getDebugString());
		self::assertSame($key->getHash(), (string) $key);
		self::assertStringStartsWith('k1:', $key->getHash());
		self::assertSame(
			[
				'collection' => 'post_user',
				'values' => ['post_id' => 10, 'user_id' => 4],
			],
			$key->jsonSerialize(),
		);
	}

	public function testSimpleAndCompositeKeyValueAccessBehavior(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();
		$postUser = (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->end()
			->field('user_id', 'int')->end();

		self::assertSame(10, $users->getKey(10)->getValue());

		$this->expectException(CompositeKeyException::class);
		$postUser->getKey([10, 4])->getValue();
	}

	public function testInvalidKeyInputsAreRejected(): void
	{
		$postUser = (new Registry())
			->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->column('post_ref')->end()
			->field('user_id', 'int')->column('user_ref')->end();

		$assertions = 0;
		foreach (
			[
				fn () => $postUser->getKey(10),
				fn () => $postUser->getKey(['post_id' => 10]),
				fn () => $postUser->getKey(['post_id' => 10, 'user_id' => 4, 'extra' => true]),
				fn () => $postUser->getKey(['post_id' => 10, 'post_ref' => 10, 'user_id' => 4]),
				fn () => $postUser->getKey(['post_id' => 10, 'user_id' => null]),
			] as $scenario
		) {
			try {
				$scenario();
				self::fail('Expected invalid primary key input to be rejected.');
			} catch (InvalidPrimaryKeyException) {
				++$assertions;
			}
		}

		self::assertSame(5, $assertions);
	}

	public function testGetKeyFromRecordPrefersFieldNamesAndIgnoresExtraFields(): void
	{
		$collection = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->end()
			->field('name', 'string')->end();

		self::assertSame(
			['id' => 10],
			$collection->getKeyFromRecord(['id' => 10, 'user_id' => 10, 'name' => 'Alice'])->getValues()
		);
		self::assertSame(
			['id' => 10],
			$collection->getKeyFromRecord(['user_id' => 10, 'name' => 'Alice'])->getValues()
		);

		$this->expectException(InvalidPrimaryKeyException::class);
		$collection->getKeyFromRecord(['name' => 'Alice'], false);
	}

	public function testEquivalentKeysCompareEqualAcrossRegistryRoundTripAndRebind(): void
	{
		$registry = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();

		$users = $registry->getCollection('users');
		self::assertNotNull($users);
		$first = $users->getKey(10);

		$restored = new Registry($registry->all());
		$restoredUsers = $restored->getCollection('users');
		self::assertNotNull($restoredUsers);
		$second = $restoredUsers->getKey(['id' => 10]);
		$rebound = $restoredUsers->getKey($first);

		self::assertTrue($first->equals($second));
		self::assertSame($first->getHash(), $second->getHash());
		self::assertNotSame($first->getCollection(), $second->getCollection());
		self::assertSame($restoredUsers, $rebound->getCollection());
		self::assertTrue($rebound->equals($first));
	}

	public function testOldFieldLevelPrimaryKeyFlagsAreMigratedAndConflictsAreRejected(): void
	{
		$registry = new Registry([
			'collections' => [
				'post_user' => [
					'fields' => [
						'post_id' => ['class' => 'ON\\Data\\Definition\\Field\\Field', 'name' => 'post_id', 'type' => 'int', 'pk' => true],
						'user_id' => ['class' => 'ON\\Data\\Definition\\Field\\Field', 'name' => 'user_id', 'type' => 'int', 'pk' => true],
					],
				],
			],
		]);

		self::assertSame(['post_id', 'user_id'], $registry->all()['collections']['post_user']['primaryKey']);
		self::assertArrayNotHasKey('pk', $registry->all()['collections']['post_user']['fields']['post_id']);

		$this->expectException(ConflictingPrimaryKeyDefinitionException::class);
		new Registry([
			'collections' => [
				'users' => [
					'primaryKey' => ['uuid'],
					'fields' => [
						'id' => ['class' => 'ON\\Data\\Definition\\Field\\Field', 'name' => 'id', 'type' => 'int', 'pk' => true],
					],
				],
			],
		]);
	}
}
