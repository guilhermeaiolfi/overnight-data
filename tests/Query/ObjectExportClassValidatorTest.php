<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Query\Exception\ObjectExportException;
use ON\Data\Query\Result\ObjectExportClassValidator;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\AbstractUserDto;
use Tests\ON\Data\Fixture\AutoloadExportRow;
use Tests\ON\Data\Fixture\RequiredCtorLoader;
use Tests\ON\Data\Fixture\UserContract;
use Tests\ON\Data\PlainDataAsserts;

final class ObjectExportClassValidatorTest extends TestCase
{
	public function testAcceptsComposerAutoloadableClass(): void
	{
		$class = AutoloadExportRow::class;

		self::assertFalse(class_exists($class, autoload: false));

		ObjectExportClassValidator::assertSupported($class);

		self::assertTrue(class_exists($class, autoload: false));
	}

	public function testRejectsMissingClass(): void
	{
		$class = 'Tests\\ON\\Data\\Fixture\\MissingAutoloadExportRow';

		self::assertFalse(class_exists($class, autoload: false));

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export class "Tests\\ON\\Data\\Fixture\\MissingAutoloadExportRow" does not exist.');

		ObjectExportClassValidator::assertSupported($class);
	}

	public function testRejectsInterface(): void
	{
		$class = UserContract::class;

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export does not support interfaces');

		ObjectExportClassValidator::assertSupported($class);
	}

	public function testRejectsTrait(): void
	{
		$class = PlainDataAsserts::class;

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export does not support traits');

		ObjectExportClassValidator::assertSupported($class);
	}

	public function testRejectsAbstractClass(): void
	{
		$class = AbstractUserDto::class;

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export does not support abstract classes');

		ObjectExportClassValidator::assertSupported($class);
	}

	public function testRejectsClassWithRequiredConstructorArgs(): void
	{
		$class = RequiredCtorLoader::class;

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export requires a class with no required constructor arguments');

		ObjectExportClassValidator::assertSupported($class);
	}
}
