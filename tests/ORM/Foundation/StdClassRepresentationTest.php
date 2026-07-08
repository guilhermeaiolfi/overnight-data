<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class StdClassRepresentationTest extends TestCase
{
	public function testStdClassCanBeWritableWhenLineageExists(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: stdClass is persistable when it has representation schema, identity, and writable field lineage.'
		);
	}

	public function testStdClassWithoutLineageIsOnlyAProjectionUnlessAttached(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: a random stdClass has no persistence authority unless explicitly attached with enough binding information.'
		);
	}
}
