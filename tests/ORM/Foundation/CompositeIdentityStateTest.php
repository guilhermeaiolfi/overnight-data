<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class CompositeIdentityStateTest extends TestCase
{
	public function testKeySupportsCompositeRecordIdentitiesInDefinitionOrder(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: ON\\Data\\Key includes collection name and canonical primary-key values in definition key order.'
		);
	}
}
