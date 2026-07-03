<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class SyncConflictTest extends TestCase
{
	public function testSyncIsTheCreateAndUpdateEntryPoint(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: sync($representation) handles new representations, new records, and mutations of known RecordState.'
		);
	}

	public function testSyncDetectsStaleRepresentationConflicts(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: if baseline A1 is stale after another representation synced A2, a later A3 sync must conflict by default.'
		);
	}

	public function testSyncConflictExampleRejectsA1ToA3AfterA2WasSynced(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: representation2 changes A1 -> A2 and syncs; representation1 based on A1 changing to A3 must be rejected.'
		);
	}
}
