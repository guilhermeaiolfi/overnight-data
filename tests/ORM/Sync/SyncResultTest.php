<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\SyncFieldUpdate;
use ON\Data\ORM\Representation\Sync\SyncPlan;
use ON\Data\ORM\Representation\Sync\SyncResult;
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
		$schema = new RepresentationFieldSchema('name', $record->getCollection(), 'name');

		self::assertTrue((new SyncResult([new SyncPlan([new SyncFieldUpdate($record, 'name', 'Ada', $schema)], [])], []))->hasChanges());
	}

	public function testHasChangesIsTrueWhenRelationChangeHasChanges(): void
	{
		$record = RecordState::new((new Registry())->collection('users')->field('id', 'int')->end());
		$reference = new ToOneRelationState($record, 'profile', new RepresentationSchema($record->getCollection()));
		$reference->set(new stdClass());

		self::assertTrue((new SyncResult([], [$reference]))->hasChanges());
	}
}
