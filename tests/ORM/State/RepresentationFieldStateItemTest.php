<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationFieldStateItemTest extends TestCase
{
	use OrmFixture;

	public function testCreateOneValidatesCollection(): void
	{
		$schema = new RepresentationFieldSchema('name', $this->users(), 'name');

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Representation schema path 'name' targets collection 'users', not 'posts'.");

		RepresentationFieldStateItem::createOne(
			$schema,
			RecordState::new($this->posts(), ['title' => 'A1']),
		);
	}

	public function testCreateOneUsesCurrentRecordRevisionAsBaseline(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Ada']);
		$record->setValue('name', 'Ada Lovelace');
		$schema = new RepresentationFieldSchema('name', $this->users(), 'name');

		$item = RepresentationFieldStateItem::createOne($schema, $record);

		self::assertSame(2, $item->getBaselineRevision());
		self::assertSame('Ada Lovelace', $item->getBaselineValue());
	}
}
