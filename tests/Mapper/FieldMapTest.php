<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use InvalidArgumentException;
use ON\Data\Mapper\FieldMap;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\StatusEnum;

final class FieldMapTest extends TestCase
{
	public function testEmptyMapIsAllowed(): void
	{
		$fieldMap = FieldMap::fromArray([]);

		self::assertSame([], $fieldMap->getFields());
		self::assertNull($fieldMap->getField('items.0.id', 'id'));
	}

	public function testGetFieldSupportsSimpleNestedAndNullableEntries(): void
	{
		$fieldMap = FieldMap::fromArray([
			'id' => 'bigint',
			'status' => StatusEnum::class,
			'items.price' => 'decimal',
			'optionalAmount' => [
				'type' => 'decimal',
				'nullable' => true,
			],
		]);

		self::assertSame(
			[
				'id' => ['type' => 'bigint', 'nullable' => false],
				'status' => ['type' => StatusEnum::class, 'nullable' => false],
				'items.price' => ['type' => 'decimal', 'nullable' => false],
				'optionalAmount' => ['type' => 'decimal', 'nullable' => true],
			],
			$fieldMap->getFields(),
		);
		self::assertSame('bigint', $fieldMap->getField('id', 'id')?->getType());
		self::assertSame(StatusEnum::class, $fieldMap->getField('status', 'status')?->getType());
		self::assertSame('decimal', $fieldMap->getField('items.0.price', 'price')?->getType());
		self::assertSame('price', $fieldMap->getField('items.0.price', 'price')?->getName());
		self::assertTrue($fieldMap->getField('optionalAmount', 'optionalAmount')?->isNullable() ?? false);
	}

	public function testRootCollectionIndexesAreIgnoredWhileConfiguredPathsRemainCaseSensitive(): void
	{
		$fieldMap = FieldMap::fromArray([
			'id' => 'bigint',
			'items.price' => 'decimal',
			'Customer.balance' => 'decimal',
		]);

		self::assertSame('bigint', $fieldMap->getField('0.id', 'id')?->getType());
		self::assertSame('decimal', $fieldMap->getField('items.12.price', 'price')?->getType());
		self::assertNull($fieldMap->getField('customer.balance', 'balance'));
		self::assertNull($fieldMap->getField('missing.path', 'path'));
	}

	public function testInvalidConfiguredPathsAreRejected(): void
	{
		foreach (['', '.', 'items..price', '.items', 'items.', 'items.0.price', 'items.*.price'] as $path) {
			try {
				FieldMap::fromArray([$path => 'decimal']);
				self::fail('Expected invalid path exception was not thrown.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('FieldMap path', $exception->getMessage());
			}
		}
	}

	public function testInvalidEntriesAreRejectedAtomically(): void
	{
		$input = [
			'amount' => [
				'type' => 'decimal',
				'nullable' => true,
			],
			'broken' => [
				'type' => 'bigint',
				'unknown' => true,
			],
		];
		$before = $input;

		$this->expectException(InvalidArgumentException::class);

		try {
			FieldMap::fromArray($input);
		} finally {
			self::assertSame($before, $input);
		}
	}

	public function testInvalidTypeAndNullableValuesAreRejected(): void
	{
		$cases = [
			['amount' => ''],
			['amount' => ['type' => '']],
			['amount' => ['type' => 'decimal', 'nullable' => 'yes']],
		];

		foreach ($cases as $case) {
			try {
				FieldMap::fromArray($case);
				self::fail('Expected invalid field map entry exception was not thrown.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('amount', $exception->getMessage());
			}
		}
	}
}
