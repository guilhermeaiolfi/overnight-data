<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class CompositeIdentityStateTest extends TestCase
{
	public function testRecordIdentitySupportsCompositeKeysInDefinitionOrder(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: RecordIdentity includes collection name and canonical primary-key values in definition key order.'
		);
	}
}
