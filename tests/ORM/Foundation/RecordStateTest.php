<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class RecordStateTest extends TestCase
{
	public function testFlushWritesDirtyRecordStatesNotArbitraryObjectState(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: flush() must write dirty RecordState instances, not scrape arbitrary current PHP object state.'
		);
	}

	public function testRegistryRemainsOrmAgnostic(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Definition/Registry.php');

		foreach ([
			'ON\\Data\\ORM',
			'RecordState',
			'UnitOfWork',
			'IdentityMap',
			'LazyLoading',
			'RepresentationState',
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $contents, $forbidden);
		}
	}

	public function testSqlDialectBehaviorStaysOutsideOrmCore(): void
	{
		self::assertDirectoryDoesNotExist(dirname(__DIR__, 3) . '/src/ORM');
		self::markTestIncomplete(
			'When ORM core exists, assert it delegates dialect SQL behavior to the database/DBAL integration layer.'
		);
	}
}
