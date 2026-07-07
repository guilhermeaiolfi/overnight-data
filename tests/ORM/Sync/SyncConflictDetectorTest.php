<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\SyncConflictDetector;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class SyncConflictDetectorTest extends TestCase
{
	use OrmFixture;

	public function testA1A2A3CaseReturnsConflict(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$rep1 = $this->tracked($record, 1);
		$rep2 = $this->tracked($record, 1);
		$detector = new SyncConflictDetector();

		self::assertSame([], $detector->detect($rep2, ['name' => 'A2']));

		$record->setValue('name', 'A2');
		$conflicts = $detector->detect($rep1, ['name' => 'A3']);

		self::assertCount(1, $conflicts);
		self::assertSame('name', $conflicts[0]->getPath());
		self::assertSame('A1', $conflicts[0]->getBaselineValue());
		self::assertSame('A2', $conflicts[0]->getRecordValue());
		self::assertSame('A3', $conflicts[0]->getRepresentationValue());
	}

	public function testUnchangedRepresentationPathHasNoConflict(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A1']));
	}

	public function testChangedPathHasNoConflictWhenRecordRevisionIsUnchanged(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record, 1);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testChangedPathHasNoConflictWhenRecordChangedButFieldStillEqualsBaselineValue(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($record, 1);
		$record->setValue('email', 'b@example.test');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testChangedPathHasNoConflictWhenRepresentationAlreadyEqualsCurrentRecordValue(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testReadOnlyBindingIsIgnored(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record, 1, writable: false);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, []));
	}

	public function testMissingCurrentValueThrows(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		$this->expectException(SyncException::class);
		(new SyncConflictDetector())->detect($tracked, []);
	}

	public function testMissingHistoryRevisionThrowsClearException(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record, 99);

		$this->expectException(StateException::class);
		$tracked->getFieldItem('name')->getBaselineValue();
	}

	public function testA1A2A3CaseReturnsConflictForNewRecord(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$rep1 = $this->tracked($record, 1);
		$rep2 = $this->tracked($record, 1);
		$detector = new SyncConflictDetector();

		self::assertSame([], $detector->detect($rep2, ['name' => 'A2']));

		$record->setValue('name', 'A2');
		$conflicts = $detector->detect($rep1, ['name' => 'A3']);

		self::assertCount(1, $conflicts);
		self::assertSame('A1', $conflicts[0]->getBaselineValue());
		self::assertSame('A2', $conflicts[0]->getRecordValue());
		self::assertSame('A3', $conflicts[0]->getRepresentationValue());
	}

	/**
	 * @return array{RecordState, RepresentationState}
	 */
	private function changedRecordScenario(string $recordName): array
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record, 1);
		$record->setValue('name', $recordName);

		return [$record, $tracked];
	}

	private function tracked(RecordState $record, int $revision, bool $writable = true): RepresentationState
	{
		$binding = new RepresentationBinding($record->getCollection());
		$field = new RepresentationFieldBinding('name', $record->getCollection(), 'name', $writable);
		$binding->addField($field);

		return new RepresentationState($binding, [
			new RepresentationFieldStateItem($field, $record, 'name', $revision),
		]);
	}
}
