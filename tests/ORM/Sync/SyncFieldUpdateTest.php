<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\Sync\SyncFieldUpdate;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class SyncFieldUpdateTest extends TestCase
{
	use OrmFixture;

	public function testExposesRecordFieldValueAndBinding(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationFieldBinding('name', $record->getCollection(), 'name');
		$update = new SyncFieldUpdate($record, 'name', 'A2', $binding);

		self::assertSame($record, $update->getRecord());
		self::assertSame('name', $update->getField());
		self::assertSame('A2', $update->getValue());
		self::assertSame($binding, $update->getBinding());
	}

	public function testRejectsEmptyField(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationFieldBinding('name', $record->getCollection(), 'name');

		$this->expectException(SyncException::class);
		new SyncFieldUpdate($record, '', 'A2', $binding);
	}

	public function testDoesNotMutateRecord(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationFieldBinding('name', $record->getCollection(), 'name');

		new SyncFieldUpdate($record, 'name', 'A2', $binding);

		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame(1, $record->getRevision());
	}
}
