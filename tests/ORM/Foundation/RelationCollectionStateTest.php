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

	public function testPlainArrayAppendIsNotWritableRelationPersistenceOperation(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: $user->posts[] = $post may be a local projection change, but it is not a writable relation persistence operation because plain arrays cannot notify the ORM or encode loadedness/replacement intent.'
		);
	}

	public function testWritableRelationAddRemoveGoesThroughOrmOwnedCollectionOrExplicitApi(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: relation add/remove persistence must go through an ORM-owned RelatedCollection/TrackedRelationCollection or a future explicit EntityManager API such as add($owner, $relation, $child).'
		);
	}

	public function testOrmOwnedRelationCollectionOwnsRelationTrackingSemantics(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: the relation collection owns owner record, relation definition/ref, loaded state, added/removed children, child RepresentationBinding, and backing collection.'
		);
	}

	public function testRelationCollectionAppliesChildBindingWhenChildIsAdded(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: adding a new relation child applies the relation item RepresentationBinding; this documents the future name without implementing runtime collection behavior.'
		);
	}

	public function testThirdPartyCollectionsAreBackingAdaptersNotRelationSemanticsOwners(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: Doctrine, Illuminate, Loophp, or other collections may later back storage/API through adapters or factories, but ORM-owned relation tracking keeps the persistence semantics.'
		);
	}
}
