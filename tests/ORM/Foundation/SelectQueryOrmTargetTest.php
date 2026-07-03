<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class SelectQueryOrmTargetTest extends TestCase
{
	public function testSelectQueryRemainsTheReadQueryApi(): void
	{
		self::assertTrue(class_exists(SelectQuery::class));
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
	}

	public function testNoWithApiIsIntroducedForRelationLoading(): void
	{
		self::assertFalse(method_exists(SelectQuery::class, 'with'));
		self::assertFalse(method_exists(RelationRef::class, 'with'));
	}

	public function testDirectSelectedFieldsCanHaveWritableLineage(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: direct selected fields such as $u->name->as("userName") may be writable when identity lineage exists.'
		);
	}

	public function testExpressionSelectedValuesAreReadOnlyByDefault(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: expression selections such as upper(name) are read-only unless future explicit reverse mapping exists.'
		);
	}
}
