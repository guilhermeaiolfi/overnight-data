<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\ORM\Relation\RelationCollectionChangeSet;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelationCollectionChangeSetTest extends TestCase
{
	public function testExposesAddedAndRemovedArrays(): void
	{
		$added = [new stdClass()];
		$removed = [new stdClass()];
		$changeSet = new RelationCollectionChangeSet($added, $removed);

		self::assertSame($added, $changeSet->getAdded());
		self::assertSame($removed, $changeSet->getRemoved());
	}

	public function testPreservesInsertionOrder(): void
	{
		$firstAdded = new stdClass();
		$secondAdded = new stdClass();
		$firstRemoved = new stdClass();
		$secondRemoved = new stdClass();

		$changeSet = new RelationCollectionChangeSet(
			[$firstAdded, $secondAdded],
			[$firstRemoved, $secondRemoved]
		);

		self::assertSame([$firstAdded, $secondAdded], $changeSet->getAdded());
		self::assertSame([$firstRemoved, $secondRemoved], $changeSet->getRemoved());
	}

	public function testIsEmptyIsTrueOnlyWhenNoChangesAndNoFullReplacement(): void
	{
		self::assertTrue((new RelationCollectionChangeSet([], []))->isEmpty());
		self::assertFalse((new RelationCollectionChangeSet([new stdClass()], []))->isEmpty());
		self::assertFalse((new RelationCollectionChangeSet([], [new stdClass()]))->isEmpty());
	}

	public function testIsEmptyIsFalseWhenFullReplacementIsTrue(): void
	{
		$changeSet = new RelationCollectionChangeSet([], [], true);

		self::assertTrue($changeSet->isFullReplacement());
		self::assertFalse($changeSet->isEmpty());
	}
}
