<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Collection\PrimaryKeyValue;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyDefinitionTest extends TestCase
{
	public function testSinglePrimaryKeyKeepsScalarUrlIdBehavior(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('article')
			->field('id', 'int')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('article');

		$identity = $collection?->getPrimaryKey()->extract(['id' => 123]);

		self::assertNotNull($identity);
		self::assertSame(['id' => 123], $identity->getValues());
		self::assertSame('123', $identity->toUrlId());
		self::assertTrue($identity->isComplete());
		self::assertSame(123, $identity->getValue('id'));
		self::assertSame($collection, $identity->getCollection());
	}

	public function testCompositePrimaryKeyRoundTripsThroughStableUrlId(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->field('tenant_id', 'int')->column('tenant_id')->primaryKey(true)->end()
			->field('slug', 'string')->column('slug')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$primaryKey = $collection?->getPrimaryKey();
		$identity = $primaryKey?->extract(['tenant_id' => 10, 'slug' => 'home']);

		self::assertNotNull($identity);
		self::assertNotNull($primaryKey);
		self::assertTrue($primaryKey->isComposite());
		self::assertSame(['tenant_id', 'slug'], $primaryKey->getFieldNames());
		self::assertSame(['tenant_id', 'slug'], $primaryKey->getColumns());

		$decoded = $primaryKey->getValueFromUrlId($identity->toUrlId());

		self::assertSame(['tenant_id' => 10, 'slug' => 'home'], $decoded->getValues());
	}

	public function testCompositePrimaryKeyCanExtractByColumnNameAndNormalizeInput(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->field('tenantId', 'int')->column('tenant_id')->primaryKey(true)->end()
			->field('slug', 'string')->column('page_slug')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$primaryKey = $collection?->getPrimaryKey();
		$identity = $primaryKey?->extract([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]);

		self::assertNotNull($identity);
		self::assertSame(['tenantId' => 7, 'slug' => 'about'], $identity->getValues());
		self::assertSame([], $primaryKey?->getMissingFieldNames([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]));
		self::assertSame($identity, $primaryKey?->getValue($identity));
		self::assertInstanceOf(PrimaryKeyValue::class, $primaryKey?->getValue(['tenant_id' => 7, 'page_slug' => 'about']));
	}
}
