<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\SyncConflictDetector;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class SyncConflictDetectorTest extends TestCase
{
	use OrmFixture;

	public function testA1A2A3CaseReturnsConflict(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$rep1 = $this->tracked($record);
		$rep2 = $this->tracked($record);
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

	public function testChangedPathHasNoConflictWhenRecordValueEqualsBaseline(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testChangedPathHasNoConflictWhenRecordChangedButFieldStillEqualsBaselineValue(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($record);
		$record->setValue('email', 'b@example.test');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testChangedPathHasNoConflictWhenRepresentationAlreadyEqualsCurrentRecordValue(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2']));
	}

	public function testReadOnlySchemaIsIgnored(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => 'A1']);
		$tracked = $this->tracked($record, writable: false);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, []));
	}

	public function testMissingCurrentValueIsSkipped(): void
	{
		[, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, []));
	}

	public function testMissingBaselineHasNoConflictWithLaterRecordValue(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), []);
		$tracked = $this->tracked($record);
		$record->setValue('name', 'A2');

		self::assertFalse($tracked->hasBaseline('name'));
		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A3']));
	}

	public function testNullBaselineDetectsConflictWithLaterRecordValue(): void
	{
		$record = RecordState::clean($this->users()->getKey(10), ['name' => null]);
		$tracked = $this->tracked($record);
		$record->setValue('name', 'A2');

		$conflict = (new SyncConflictDetector())->detect($tracked, ['name' => 'A3'])[0];

		self::assertTrue($tracked->hasBaseline('name'));
		self::assertNull($conflict->getBaselineValue());
	}

	public function testA1A2A3CaseReturnsConflictForNewRecord(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$rep1 = $this->tracked($record);
		$rep2 = $this->tracked($record);
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
		$tracked = $this->tracked($record);
		$record->setValue('name', $recordName);

		return [$record, $tracked];
	}

	private function tracked(RecordState $record, bool $writable = true): RepresentationState
	{
		$schema = new RepresentationSchema($record->getCollection());
		$field = new RepresentationFieldSchema('name', $record->getCollection(), 'name', $writable);
		$schema->addField($field);

		return RepresentationState::fromRecords($schema, ['' => $record]);
	}
}
