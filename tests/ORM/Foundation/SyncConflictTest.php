<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Session;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class SyncConflictTest extends TestCase
{
	use OrmFixture;

	public function testSyncIsTheCreateAndUpdateEntryPoint(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = $this->representation(['name' => 'A1']);

		$session->sync($representation, $this->userSchema());
		$tracked = $session->getRepresentations()->get($representation);
		$record = $tracked->getSingleRecord();

		self::assertInstanceOf(RecordState::class, $record);
		self::assertSame('A1', $record->getValue('name'));

		$representation->name = 'A2';
		$result = $session->sync($representation);

		self::assertTrue($result->hasChanges());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testSyncDetectsStaleRepresentationConflicts(): void
	{
		[$session, $firstRepresentation, $secondRepresentation, $record] = $this->staleSyncScenario();

		$secondRepresentation->name = 'A2';
		$session->sync($secondRepresentation);
		$firstRepresentation->name = 'A3';

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('1 conflict');
		$this->expectExceptionMessage('name');

		try {
			$session->sync($firstRepresentation);
		} finally {
			self::assertSame('A2', $record->getValue('name'));
		}
	}

	public function testSyncConflictExampleRejectsA1ToA3AfterA2WasSynced(): void
	{
		[$session, $firstRepresentation, $secondRepresentation, $record] = $this->staleSyncScenario();

		$secondRepresentation->name = 'A2';
		$session->sync($secondRepresentation);
		$firstRepresentation->name = 'A3';

		try {
			$session->sync($firstRepresentation);
			self::fail('Expected stale representation sync to be rejected.');
		} catch (SyncException $exception) {
			self::assertStringContainsString('1 conflict', $exception->getMessage());
			$tracked = $session->getRepresentations()->get($firstRepresentation);
			self::assertSame('A1', $tracked?->getFieldItem('name')->getBaselineValue());
			self::assertSame('A2', $record->getValue('name'));
			self::assertSame('A3', $firstRepresentation->name);
		}
	}

	/**
	 * @return array{Session, object, object, RecordState}
	 */
	private function staleSyncScenario(): array
	{
		$session = new Session(new RecordingCommandExecutor());
		$record = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'A1']);
		$session->getRecords()->add($record);
		$firstRepresentation = $this->representation(['name' => 'A1']);
		$secondRepresentation = $this->representation(['name' => 'A1']);
		$this->adoptRecord($session, $firstRepresentation, $this->userSchema(), $record);
		$this->adoptRecord($session, $secondRepresentation, $this->userSchema(), $record);

		return [$session, $firstRepresentation, $secondRepresentation, $record];
	}
}
