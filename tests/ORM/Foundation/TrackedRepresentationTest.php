<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class TrackedRepresentationTest extends TestCase
{
	public function testRepresentationCanMapToMultipleRecordStates(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: one representation may bind userName, postTitle, and authorName to separate RecordState instances.'
		);
	}

	public function testTrackedRepresentationStoresBaselineRevisionsLineageAndWritableFlags(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: TrackedRepresentation must track baseline record revisions, field lineage, and per-field writable/read-only status.'
		);
	}
}
