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

	public function testRepresentationBindingMayBeReusedAsChildOrRelationItemTemplate(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: RepresentationBinding is the reusable mapping shape for root, child, and relation item bindings; do not add a separate child template unless implementation proves it necessary.'
		);
	}

	public function testTrackedRepresentationIsConcreteAndNotATemplate(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: TrackedRepresentation means concrete object/value plus applied binding plus baseline record revisions; it must not be used as a reusable child or relation item template.'
		);
	}

	public function testAppliedBindingsMayNeedLocalRecordHandlesForNewChildren(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: applied bindings for multiple new relation children may need RecordState or local in-memory record handles because repeated keyless refs such as posts[no-key].title are ambiguous.'
		);
	}
}
