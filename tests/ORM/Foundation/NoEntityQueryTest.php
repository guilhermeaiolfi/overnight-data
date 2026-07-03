<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class NoEntityQueryTest extends TestCase
{
	public function testNoEntityQueryClassIsIntroduced(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityQuery.php');
	}
}
