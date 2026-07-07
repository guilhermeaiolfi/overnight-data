<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationStateTest extends TestCase
{
	use OrmFixture;

	public function testExposesBindingWithoutRepresentationObject(): void
	{
		$binding = new RepresentationBinding();
		$tracked = new RepresentationState($binding, []);

		self::assertSame($binding, $tracked->getBinding());
		self::assertFalse(method_exists($tracked, 'getRepresentation'));
	}

	public function testStoresPathKeyedFieldItems(): void
	{
		[$binding, $item] = $this->stateParts();
		$state = new RepresentationState($binding, [$item]);

		self::assertTrue($state->hasFieldItem('name'));
		self::assertSame($item, $state->getFieldItem('name'));
		self::assertSame([$item], $state->getFieldItems());
		self::assertSame([$item], $state->getWritableFieldItems());
	}

	public function testMissingFieldItemThrows(): void
	{
		$state = new RepresentationState(new RepresentationBinding(), []);

		$this->expectException(StateException::class);
		$state->getFieldItem('missing');
	}

	public function testAcceptSyncedRecordsAdvancesOnlyTouchedItemBaselines(): void
	{
		[$binding, $name] = $this->stateParts();
		$emailBinding = new RepresentationFieldBinding('email', $this->users(), 'email');
		$emailRecord = RecordState::new($this->users(), ['email' => 'a@example.test']);
		$binding->addField($emailBinding);
		$state = new RepresentationState($binding, [
			$name,
			new RepresentationFieldStateItem($emailBinding, $emailRecord, 'email', $emailRecord->getRevision()),
		]);

		$name->getRecord()->setValue('name', 'A2');
		$emailRecord->setValue('email', 'b@example.test');
		$state->acceptSyncedRecords([$name->getRecord()->getStateHash() => $name->getRecord()]);

		self::assertSame(2, $state->getFieldItem('name')->getBaselineRevision());
		self::assertSame(1, $state->getFieldItem('email')->getBaselineRevision());
	}

	/**
	 * @return array{RepresentationBinding, RepresentationFieldStateItem}
	 */
	private function stateParts(): array
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$field = new RepresentationFieldBinding('name', $record->getCollection(), 'name');
		$binding->addField($field);

		return [$binding, new RepresentationFieldStateItem($field, $record, 'name', $record->getRevision())];
	}
}
