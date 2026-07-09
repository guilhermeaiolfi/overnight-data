<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\ORM\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class NoPersistApiTest extends TestCase
{
	public function testNoPublicPersistApiIsIntroducedForCreateOrUpdate(): void
	{
		$entityManagerClass = 'ON\\Data\\ORM\\EntityManager';

		if (! class_exists($entityManagerClass)) {
			self::assertTrue(true);

			return;
		}

		$reflection = new ReflectionClass($entityManagerClass);

		self::assertFalse($reflection->hasMethod('persist'));
		self::assertTrue($reflection->hasMethod('sync'));
	}

	public function testDeletionApiRemainsExplicitAndSeparateFromSync(): void
	{
		$reflection = new ReflectionClass(Session::class);

		self::assertTrue($reflection->hasMethod('sync'));
		self::assertTrue($reflection->hasMethod('remove'));
		self::assertFalse($reflection->hasMethod('persist'));
		self::assertFalse($reflection->hasMethod('delete'));
	}
}
