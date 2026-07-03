<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;

final class RelationCollectionStateTest extends TestCase
{
	public function testLazyLoadingDefaultIsPrevented(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: default lazy loading policy is PREVENT, and accessing unloaded relations should throw in strict/default mode.'
		);
	}

	public function testRelationCollectionStateDistinguishesLoadedness(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: relation state distinguishes UNLOADED, PARTIALLY_LOADED, and FULLY_LOADED collections.'
		);
	}

	public function testUnloadedCollectionIsNeverTreatedAsEmpty(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: unloaded collections are unknown, not empty; replacement requires explicit full replacement semantics.'
		);
	}

	public function testCascadeBehaviorComesFromRelationDefinitions(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: ORM write planning reads cascade intent from relation definitions instead of duplicated ORM metadata.'
		);
	}
}
