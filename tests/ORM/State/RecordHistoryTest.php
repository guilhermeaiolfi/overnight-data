<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordHistory;
use PHPUnit\Framework\TestCase;

final class RecordHistoryTest extends TestCase
{
	public function testRecordsAndReturnsSnapshotsByRevision(): void
	{
		$history = new RecordHistory();
		$history->record(1, ['name' => 'A1']);
		$history->record(2, ['name' => 'A2']);

		self::assertSame(['name' => 'A1'], $history->getSnapshot(1));
		self::assertTrue($history->hasRevision(2));
		self::assertSame('A2', $history->getValue(2, 'name'));
	}

	public function testRejectsDuplicateRevisions(): void
	{
		$history = new RecordHistory();
		$history->record(1, []);

		$this->expectException(StateException::class);
		$history->record(1, []);
	}

	public function testMissingRevisionThrows(): void
	{
		$history = new RecordHistory();

		$this->expectException(StateException::class);
		$history->getSnapshot(1);
	}

	public function testMissingFieldThrows(): void
	{
		$history = new RecordHistory();
		$history->record(1, ['name' => 'A1']);

		$this->expectException(StateException::class);
		$history->getValue(1, 'email');
	}

	public function testMissingFieldIsDifferentFromNull(): void
	{
		$history = new RecordHistory();
		$history->record(1, ['name' => null]);

		self::assertTrue($history->hasValue(1, 'name'));
		self::assertFalse($history->hasValue(1, 'email'));
		self::assertNull($history->getValue(1, 'name'));
	}

	public function testReturnsOldestAndLatestRevision(): void
	{
		$history = new RecordHistory();
		$history->record(2, []);
		$history->record(1, []);

		self::assertSame([1, 2], $history->getRevisions());
		self::assertSame(1, $history->getOldestRevision());
		self::assertSame(2, $history->getLatestRevision());
	}

	public function testPruneBeforeRemovesOldRevisions(): void
	{
		$history = new RecordHistory();
		$history->record(1, ['name' => 'A1']);
		$history->record(2, ['name' => 'A2']);
		$history->record(3, ['name' => 'A3']);

		$history->pruneBefore(3);

		self::assertSame([3], $history->getRevisions());
		self::assertFalse($history->hasRevision(2));
	}

	public function testPruneBeforeNeverRemovesAllHistory(): void
	{
		$history = new RecordHistory();
		$history->record(1, ['name' => 'A1']);
		$history->record(2, ['name' => 'A2']);

		$history->pruneBefore(99);

		self::assertSame([2], $history->getRevisions());
	}
}
