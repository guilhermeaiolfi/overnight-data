<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

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

	public function testPhase1HasNoDatabaseWriteCommands(): void
	{
		self::assertDirectoryDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/Persistence');
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

	public function testSyncPlannerPlansFieldUpdatesOnly(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: SyncPlanner returns SyncPlan field updates only; it must not group database commands or apply records.'
		);
	}

	public function testDirtyFieldAggregationBelongsToRecordStateAndFutureFlushPlanning(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: future sync-apply mutates RecordState, and future flush/write planning aggregates RecordState::getDirtyValues() into database commands.'
		);
	}

	public function testRelatedCollectionTracksRelationIntentOnly(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: RelatedCollection owns relation add/remove intent only; it does not persist, adopt, or write relations.'
		);
	}

	public function testRepresentationAdopterDoesNotSyncValues(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: RepresentationAdopter registers tracked representations only; value synchronization remains future runtime work.'
		);
	}

	public function testRepresentationValueReaderDoesNotMutateOrConvertValues(): void
	{
		self::markTestIncomplete(
			'Phase 1 boundary: RepresentationValueReader reads public representation values only; mapper conversion and mutation are future runtime work.'
		);
	}
}
