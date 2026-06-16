<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\InvalidFieldTypeException;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomFieldType;

final class FieldTypeRegistryTest extends TestCase
{
	public function testDefaultRegistryResolvesPrimitiveAliases(): void
	{
		$registry = FieldTypeRegistry::createDefault();

		self::assertSame(StringFieldType::class, $registry->get('string'));
		self::assertSame(IntFieldType::class, $registry->get('integer'));
		self::assertSame(IntFieldType::class, $registry->get('primary'));
		self::assertSame(IntFieldType::class, $registry->get('smallprimary'));
	}

	public function testDirectFieldTypeClassReferencesResolve(): void
	{
		$registry = FieldTypeRegistry::createDefault();

		self::assertSame(
			StringFieldType::class,
			$registry->resolve(FieldContext::named('name', StringFieldType::class)),
		);
	}

	public function testCustomFieldTypeRegistrationWorks(): void
	{
		$registry = (new FieldTypeRegistry())->register('custom', CustomFieldType::class);

		self::assertSame(CustomFieldType::class, $registry->get('custom'));
		self::assertSame('HELLO', CustomFieldType::toPhp('any', 'hello', FieldContext::named('value', 'custom')));
	}

	public function testInvalidFieldTypeRegistrationFails(): void
	{
		$this->expectException(InvalidFieldTypeException::class);
		(new FieldTypeRegistry())->register('broken', stdClass::class);
	}

	public function testUnknownFieldTypeResolutionIsExplicit(): void
	{
		$registry = new FieldTypeRegistry();

		self::assertNull($registry->resolve(FieldContext::named('name', 'unknown')));

		$this->expectException(FieldTypeNotFoundException::class);
		$registry->get('unknown');
	}
}
