<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\Sync\SyncFieldUpdate;
use ON\Data\ORM\Sync\SyncPlan;
use ON\Data\ORM\Sync\SyncResult;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SyncResultTest extends TestCase
{
	public function testHasChangesIsFalseWhenPlansAndRelationChangesAreUnchanged(): void
	{
		self::assertFalse((new SyncResult([new SyncPlan([], [])], []))->hasChanges());
	}

	public function testHasChangesIsTrueWhenScalarPlanHasUpdates(): void
	{
		$record = RecordState::new((new Registry())->collection('users')->field('name', 'string')->end());
		$binding = new RepresentationFieldBinding('name', $record->getCollection(), 'name');

		self::assertTrue((new SyncResult([new SyncPlan([new SyncFieldUpdate($record, 'name', 'Ada', $binding)], [])], []))->hasChanges());
	}

	public function testHasChangesIsTrueWhenRelationChangeHasChanges(): void
	{
		$record = RecordState::new((new Registry())->collection('users')->field('id', 'int')->end());
		$reference = new ToOneRelationState($record, 'profile', new RepresentationBinding($record->getCollection()));
		$reference->set(new stdClass());

		self::assertTrue((new SyncResult([], [$reference]))->hasChanges());
	}
}
