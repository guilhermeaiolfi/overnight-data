<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Field\BoolFieldType;
use ON\Data\Mapper\Field\FloatFieldType;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\PassthroughFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\MapperManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\EmptyNamesFieldType;
use Tests\ON\Data\Fixture\InvalidNamesFieldType;

final class MapperManagerFieldTypeTest extends TestCase
{
	public function testDefaultManagerResolvesPrimitiveAliases(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame(StringFieldType::class, $manager->getFieldType('string'));
		self::assertSame(PassthroughFieldType::class, $manager->getFieldType('text'));
		self::assertSame(BoolFieldType::class, $manager->getFieldType('boolean'));
		self::assertSame(IntFieldType::class, $manager->getFieldType('integer'));
		self::assertSame(IntFieldType::class, $manager->getFieldType('primary'));
		self::assertSame(IntFieldType::class, $manager->getFieldType('smallprimary'));
		self::assertSame(FloatFieldType::class, $manager->getFieldType('double'));
	}

	public function testAliasLookupRemainsCaseInsensitive(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame(IntFieldType::class, $manager->getFieldType('INTEGER'));
		self::assertSame(BoolFieldType::class, $manager->getFieldType('BoOlEaN'));
	}

	public function testDirectFieldTypeClassReferencesResolve(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame(
			StringFieldType::class,
			$manager->resolveFieldType(FieldContext::named('name', StringFieldType::class)),
		);
	}

	public function testCustomFieldTypeRegistrationWorks(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(CustomFieldType::class);

		self::assertSame(CustomFieldType::class, $manager->getFieldType('custom'));
		self::assertSame('HELLO', CustomFieldType::toPhp('hello', FieldContext::named('value', 'custom')));
	}

	public function testLaterFieldTypeRegistrationReplacesExistingAlias(): void
	{
		$manager = MapperManager::createDefault($this->gateway());
		$manager->register(CustomFieldType::class);

		self::assertSame(CustomFieldType::class, $manager->getFieldType('custom'));
	}

	public function testEmptyAndInvalidNamesAreRejected(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(InvalidMapperComponentException::class);
		$manager->register(EmptyNamesFieldType::class);
	}

	public function testBlankNamesAreRejected(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(InvalidMapperComponentException::class);
		$manager->register(InvalidNamesFieldType::class);
	}

	public function testUnknownFieldTypeResolutionIsExplicit(): void
	{
		$manager = new MapperManager($this->gateway());

		self::assertNull($manager->resolveFieldType(FieldContext::named('name', 'unknown')));

		$this->expectException(FieldTypeNotFoundException::class);
		$manager->getFieldType('unknown');
	}

	public function testPrimitiveFieldTypesExposeSameDirectStaticContractAsCustomFieldTypes(): void
	{
		$fieldTypes = [
			StringFieldType::class,
			PassthroughFieldType::class,
			BoolFieldType::class,
			IntFieldType::class,
			FloatFieldType::class,
			CustomFieldType::class,
		];

		foreach ($fieldTypes as $fieldType) {
			self::assertTrue(is_a($fieldType, FieldTypeInterface::class, true));

			$getNames = new ReflectionMethod($fieldType, 'getNames');
			self::assertTrue($getNames->isPublic());
			self::assertTrue($getNames->isStatic());
			self::assertSame($fieldType, $getNames->getDeclaringClass()->getName());

			$getStorageType = new ReflectionMethod($fieldType, 'getStorageType');
			self::assertTrue($getStorageType->isPublic());
			self::assertTrue($getStorageType->isStatic());
			self::assertSame($fieldType, $getStorageType->getDeclaringClass()->getName());

			$toPhp = new ReflectionMethod($fieldType, 'toPhp');
			self::assertTrue($toPhp->isPublic());
			self::assertTrue($toPhp->isStatic());
			self::assertSame($fieldType, $toPhp->getDeclaringClass()->getName());

			$fromPhp = new ReflectionMethod($fieldType, 'fromPhp');
			self::assertTrue($fromPhp->isPublic());
			self::assertTrue($fromPhp->isStatic());
			self::assertSame($fieldType, $fromPhp->getDeclaringClass()->getName());
		}
	}

	private function gateway(): ConversionGateway
	{
		return new ConversionGateway();
	}
}
