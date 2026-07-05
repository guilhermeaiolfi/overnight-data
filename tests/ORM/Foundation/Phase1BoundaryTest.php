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

final class Phase1BoundaryTest extends TestCase
{
	public function testPhase1HasNoPublicEntityManager(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityManager'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityManager.php');
	}

	public function testPhase1HasNoPublicSyncApi(): void
	{
		self::assertFalse(function_exists('ON\\Data\\ORM\\sync'));
	}

	public function testPhase1HasNoFlushRuntime(): void
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

	public function testPhase1HasNoEntityQuery(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityQuery.php');
	}

	public function testPhase1HasNoWithApi(): void
	{
		self::assertFalse(function_exists('ON\\Data\\ORM\\with'));
	}

	public function testScalarRepresentationSynchronizerPlansFieldUpdatesOnly(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: ScalarRepresentationSynchronizer returns SyncPlan field updates only; it must not group database commands or apply records.'
		);
	}

	public function testDirtyFieldAggregationBelongsToRecordStateAndFutureFlushPlanning(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: future sync-apply mutates RecordState, and future flush/write planning aggregates RecordState::getDirtyValues() into database commands.'
		);
	}

	public function testToManyRelationStateTracksRelationIntentOnly(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: ToManyRelationState owns relation add/remove intent only; it does not persist, adopt, or write relations.'
		);
	}

	public function testRepresentationAdopterDoesNotSyncValues(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: RepresentationAdopter registers tracked representations only; value synchronization remains future runtime work.'
		);
	}

	public function testRepresentationReaderDoesNotMutateOrConvertValues(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: RepresentationReader reads public representation values only; mapper conversion and mutation are future runtime work.'
		);
	}
}
