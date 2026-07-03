<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\Sync\SyncConflict;
use ON\Data\ORM\Sync\SyncFieldUpdate;
use ON\Data\ORM\Sync\SyncPlan;
use PHPUnit\Framework\TestCase;

final class SyncPlanTest extends TestCase
{
	public function testExposesUpdates(): void
	{
		$update = $this->update('name', 'A2');

		self::assertSame([$update], (new SyncPlan([$update], []))->getUpdates());
	}

	public function testExposesConflicts(): void
	{
		$conflict = new SyncConflict('name', 'A1', 'A2', 'A3');

		self::assertSame([$conflict], (new SyncPlan([], [$conflict]))->getConflicts());
	}

	public function testHasUpdatesWorks(): void
	{
		self::assertFalse((new SyncPlan([], []))->hasUpdates());
		self::assertTrue((new SyncPlan([$this->update('name', 'A2')], []))->hasUpdates());
	}

	public function testHasConflictsWorks(): void
	{
		self::assertFalse((new SyncPlan([], []))->hasConflicts());
		self::assertTrue((new SyncPlan([], [new SyncConflict('name', 'A1', 'A2', 'A3')]))->hasConflicts());
	}

	public function testIsEmptyIsTrueForNoUpdatesOrConflicts(): void
	{
		self::assertTrue((new SyncPlan([], []))->isEmpty());
	}

	public function testIsEmptyIsFalseWithUpdates(): void
	{
		self::assertFalse((new SyncPlan([$this->update('name', 'A2')], []))->isEmpty());
	}

	public function testIsEmptyIsFalseWithConflicts(): void
	{
		self::assertFalse((new SyncPlan([], [new SyncConflict('name', 'A1', 'A2', 'A3')]))->isEmpty());
	}

	public function testPreservesInsertionOrder(): void
	{
		$firstUpdate = $this->update('name', 'A2');
		$secondUpdate = $this->update('email', 'a@example.test');
		$firstConflict = new SyncConflict('name', 'A1', 'A2', 'A3');
		$secondConflict = new SyncConflict('email', 'a@example.test', 'b@example.test', 'c@example.test');

		$plan = new SyncPlan([$firstUpdate, $secondUpdate], [$firstConflict, $secondConflict]);

		self::assertSame([$firstUpdate, $secondUpdate], $plan->getUpdates());
		self::assertSame([$firstConflict, $secondConflict], $plan->getConflicts());
	}

	private function update(string $field, mixed $value): SyncFieldUpdate
	{
		$record = RecordState::new($this->users(), [$field => 'A1']);
		$binding = new RepresentationFieldBinding($field, RecordFieldRef::forState($record, $field));

		return new SyncFieldUpdate($record, $field, $value, $binding);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();
	}
}
