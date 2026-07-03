<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;

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

	public function testClassTargetWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: query($users)->to(User::class) with no explicit select() implies the root collection default scalar fields.'
		);
	}

	public function testStdClassTargetWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		self::assertTrue(class_exists(stdClass::class));
		self::markTestIncomplete(
			'Phase 0 skeleton: query($users)->to(stdClass::class) with no explicit select() implies root scalar fields and may be tracked as one root record representation.'
		);
	}

	public function testArrayTargetWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: query($users)->to([]) with no explicit select() implies root scalar field arrays.'
		);
	}

	public function testImplicitDefaultSelectionAppliesOnlyToRootCollection(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: default selection from to(...) is root-only and must not select fields from related collections.'
		);
	}

	public function testToTargetDoesNotAutoLoadRelations(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: to(User::class), to(stdClass::class), and to([]) must not auto-load relations; relation loading remains explicit.'
		);
	}

	public function testExplicitSelectDisablesDefaultRootFieldSelection(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: once select($u->id, $u->name) is explicit, the default root scalar field selection is not added.'
		);
	}

	public function testHiddenIdentityFieldsDoNotLeakIntoMappedRepresentation(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: hidden identity/tracking fields may be selected internally but must not appear in the final mapped representation unless explicitly selected or mapped.'
		);
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

	public function testPartialExplicitSelectionsDoNotOverwriteMissingFieldsDuringSync(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: partial explicit selections must sync only tracked selected fields and must not overwrite missing root fields.'
		);
	}

	public function testDefaultSelectedRootRepresentationIsNormalWritableRootCase(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: a fully default-selected root representation can be treated as the normal writable root case.'
		);
	}

	public function testPartialClassRepresentationsAreProjectionLikeUnlessTrackedFieldByField(): void
	{
		self::markTestIncomplete(
			'Phase 0 skeleton: partial class representations are projection-like unless the ORM explicitly tracks selected fields field-by-field.'
		);
	}
}
