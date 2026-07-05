<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class OrmArchitectureBoundaryTest extends TestCase
{
	public function testHasNoPublicEntityManager(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityManager'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityManager.php');
	}

	public function testHasNoGlobalSyncFunction(): void
	{
		self::assertFalse(function_exists('ON\\Data\\ORM\\sync'));
	}

	public function testHasNoGlobalFlushFunction(): void
	{
		self::assertFalse(function_exists('ON\\Data\\ORM\\flush'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/Flush.php');
	}

	public function testPersistenceCommandsRemainNeutralDataObjects(): void
	{
		$commandClasses = [
			InsertCommand::class,
			UpdateCommand::class,
			DeleteCommand::class,
		];

		foreach ($commandClasses as $commandClass) {
			$reflection = new ReflectionClass($commandClass);

			self::assertTrue($reflection->implementsInterface(CommandInterface::class));
			self::assertFalse($reflection->hasMethod('execute'));
		}
	}

	public function testPersistenceLayerDoesNotContainDatabaseSpecificCode(): void
	{
		$persistenceRoot = dirname(__DIR__, 3) . '/src/ORM/Persistence';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($persistenceRoot));
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'PDO',
			' SQL',
			' sql',
		];

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = file_get_contents($file->getPathname());
			self::assertNotFalse($contents);

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					$pattern,
					$contents,
					sprintf('Database-specific persistence pattern "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}

	public function testHasNoEntityQuery(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityQuery.php');
	}

	public function testHasNoGlobalWithFunction(): void
	{
		self::assertFalse(function_exists('ON\\Data\\ORM\\with'));
	}
}
