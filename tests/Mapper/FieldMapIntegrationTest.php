<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use DateTimeImmutable;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\OtherResolver;
use Tests\ON\Data\Fixture\ReflectedArticleDto;
use Tests\ON\Data\Fixture\StatusEnum;

final class FieldMapIntegrationTest extends TestCase
{
	public function testUntypedStorageArrayUsesFieldMapForExactNumericsAndEnum(): void
	{
		$fieldMap = FieldMap::fromArray([
			'id' => 'bigint',
			'amount' => 'decimal',
			'status' => StatusEnum::class,
		]);
		$source = [
			'id' => '9223372036854775808',
			'amount' => '00012.3400',
			'status' => 'active',
		];
		$before = $source;

		$result = map($source)
			->from(StorageRepresentation::class)
			->fieldMap($fieldMap)
			->to([]);

		self::assertSame($before, $source);
		self::assertSame(
			[
				'id' => '9223372036854775808',
				'amount' => '12.34',
				'status' => StatusEnum::Active,
			],
			$result,
		);
	}

	public function testNestedListsAndRootCollectionsUseFieldMapWithoutIndexSpecificPaths(): void
	{
		$fieldMap = FieldMap::fromArray([
			'id' => 'bigint',
			'amount' => 'decimal',
			'items.id' => 'bigint',
			'items.price' => 'decimal',
		]);

		$rows = [
			[
				'id' => '9223372036854775808',
				'amount' => '0012.3400',
				'items' => [
					['id' => '10', 'price' => '0001.5000'],
					['id' => '11', 'price' => '0002.0000'],
				],
			],
		];

		$result = map($rows)
			->collection()
			->from(StorageRepresentation::class)
			->fieldMap($fieldMap)
			->to([]);

		self::assertSame(
			[
				[
					'id' => '9223372036854775808',
					'amount' => '12.34',
					'items' => [
						['id' => '10', 'price' => '1.5'],
						['id' => '11', 'price' => '2'],
					],
				],
			],
			$result,
		);
	}

	public function testFieldMapOverridesDefinitionOnlyOnExplicitPathsAndCoexistsWithArgs(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('orders');
		$definition->field('id', 'int');
		$definition->field('amount', 'float');
		$definition->field('flag', 'bool');
		$definitionBefore = $registry->all();
		$fieldMap = FieldMap::fromArray([
			'amount' => 'decimal',
		]);
		$fieldMapBefore = $fieldMap->getFields();

		$result = map([
			'id' => '42',
			'amount' => '00012.3400',
			'flag' => '1',
		])
			->from(StorageRepresentation::class)
			->args($definition, 'kept')
			->fieldMap($fieldMap)
			->to([]);

		self::assertSame(42, $result['id']);
		self::assertSame('12.34', $result['amount']);
		self::assertTrue($result['flag']);
		self::assertSame($definitionBefore, $registry->all());
		self::assertSame($fieldMapBefore, $fieldMap->getFields());
	}

	public function testCustomResolverConfiguredOnBuilderOverridesAllDefaultResolvers(): void
	{
		$fieldMap = FieldMap::fromArray([
			'id' => 'bigint',
		]);
		$source = ['id' => 42];

		$result = map($source)
			->from(StorageRepresentation::class)
			->fieldMap($fieldMap)
			->resolver(OtherResolver::class)
			->to([]);

		self::assertSame(['id' => '42'], $result);
	}

	public function testReflectedDtoMapsStorageToDtoAndDtoToWireWithoutDefinitions(): void
	{
		$dto = map([
			'status' => 'active',
			'priority' => 1,
			'publishedAt' => '2026-06-18 13:45:12',
		])
			->from(StorageRepresentation::class)
			->to(ReflectedArticleDto::class);

		$result = map($dto)
			->as(WireRepresentation::class)
			->to([]);

		self::assertSame(StatusEnum::Active, $dto->status);
		self::assertInstanceOf(DateTimeImmutable::class, $dto->publishedAt);
		self::assertSame('active', $result['status']);
		self::assertSame('2026-06-18T13:45:12+00:00', $result['publishedAt']);
	}

	public function testFieldMapCanDriveUntypedStdClassOutput(): void
	{
		$result = map([
			'id' => '9223372036854775808',
			'amount' => '00012.3400',
		])
			->from(StorageRepresentation::class)
			->fieldMap(FieldMap::fromArray([
				'id' => 'bigint',
				'amount' => 'decimal',
			]))
			->to(stdClass::class);

		self::assertInstanceOf(stdClass::class, $result);
		self::assertSame('9223372036854775808', $result->id);
		self::assertSame('12.34', $result->amount);
	}
}
