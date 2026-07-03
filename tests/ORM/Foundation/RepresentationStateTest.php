<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class RepresentationStateTest extends TestCase
{
	public function testRepresentationCanMapToMultipleRecordStates(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: one representation may bind userName, postTitle, and authorName to separate RecordState instances.'
		);
	}

	public function testRepresentationStateStoresBaselineLineageAndWritableFlags(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: RepresentationState must track baseline values, field lineage, and per-slot writable/read-only status.'
		);
	}
}
