<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\State\RepresentationState;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationStateTest extends TestCase
{
	use OrmFixture;

	public function testExposesSchemaWithoutRepresentationObject(): void
	{
		$schema = new RepresentationSchema($this->users());
		$tracked = new RepresentationState($schema, []);

		self::assertSame($schema, $tracked->getSchema());
		self::assertFalse(method_exists($tracked, 'getRepresentation'));
	}

	public function testStoresPathKeyedFieldItems(): void
	{
		[$schema, $item] = $this->stateParts();
		$state = new RepresentationState($schema, [$item]);

		self::assertTrue($state->hasFieldItem('name'));
		self::assertSame($item, $state->getFieldItem('name'));
		self::assertSame([$item], $state->getFieldItems());
		self::assertSame([$item], $state->getWritableFieldItems());
	}

	public function testMissingFieldItemThrows(): void
	{
		$state = new RepresentationState(new RepresentationSchema($this->users()), []);

		$this->expectException(StateException::class);
		$state->getFieldItem('missing');
	}

	public function testGetRootRecordResolvesRecordMatchingSchemaRootCollection(): void
	{
		[$schema, $item] = $this->stateParts();
		$state = new RepresentationState($schema, [$item]);

		self::assertSame($item->getRecord(), $state->getRootRecord());
		self::assertSame($item->getRecord(), $state->requireRootRecord());
	}

	public function testGetRootRecordReturnsNullWhenNoRootItemAttached(): void
	{
		$state = new RepresentationState(new RepresentationSchema($this->users()), []);

		self::assertNull($state->getRootRecord());
	}

	public function testRequireRootRecordThrowsWhenNoRootItemAttached(): void
	{
		$state = new RepresentationState(new RepresentationSchema($this->users()), []);

		$this->expectException(StateException::class);
		$state->requireRootRecord();
	}

	public function testGetRootRecordIgnoresFieldItemsFromNonRootSourcePath(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$rootRecord = RecordState::new($users, ['name' => 'A1']);
		$foreignRecord = RecordState::new($posts, ['title' => 'T']);

		$schema = new RepresentationSchema($users);
		$rootField = new RepresentationFieldSchema('name', $users, 'name');
		$foreignField = new RepresentationFieldSchema('companyTitle', $posts, 'title', sourcePath: ['company']);
		$schema->addField($foreignField);
		$schema->addField($rootField);

		$state = new RepresentationState($schema, [
			new RepresentationFieldStateItem($foreignField, $foreignRecord, 'title', $foreignRecord->getRevision()),
			new RepresentationFieldStateItem($rootField, $rootRecord, 'name', $rootRecord->getRevision()),
		]);

		self::assertSame($rootRecord, $state->getRootRecord());
	}

	public function testGetRootRecordThrowsWhenRootSourceItemsDisagree(): void
	{
		$users = $this->users();
		$recordOne = RecordState::new($users, ['name' => 'A1']);
		$recordTwo = RecordState::new($users, ['name' => 'A2']);

		$schema = new RepresentationSchema($users);
		$fieldOne = new RepresentationFieldSchema('name', $users, 'name');
		$fieldTwo = new RepresentationFieldSchema('nickname', $users, 'name');
		$schema->addField($fieldOne);
		$schema->addField($fieldTwo);

		$state = new RepresentationState($schema, [
			new RepresentationFieldStateItem($fieldOne, $recordOne, 'name', $recordOne->getRevision()),
			new RepresentationFieldStateItem($fieldTwo, $recordTwo, 'name', $recordTwo->getRevision()),
		]);

		$this->expectException(StateException::class);
		$state->getRootRecord();
	}

	public function testAcceptSyncedRecordsAdvancesOnlyTouchedItemBaselines(): void
	{
		[$schema, $name] = $this->stateParts();
		$emailSchema = new RepresentationFieldSchema('email', $this->users(), 'email');
		$emailRecord = RecordState::new($this->users(), ['email' => 'a@example.test']);
		$schema->addField($emailSchema);
		$state = new RepresentationState($schema, [
			$name,
			new RepresentationFieldStateItem($emailSchema, $emailRecord, 'email', $emailRecord->getRevision()),
		]);

		$name->getRecord()->setValue('name', 'A2');
		$emailRecord->setValue('email', 'b@example.test');
		$state->acceptSyncedRecords([$name->getRecord()->getStateHash() => $name->getRecord()]);

		self::assertSame(2, $state->getFieldItem('name')->getBaselineRevision());
		self::assertSame(1, $state->getFieldItem('email')->getBaselineRevision());
	}

	/**
	 * @return array{RepresentationSchema, RepresentationFieldStateItem}
	 */
	private function stateParts(): array
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$schema = new RepresentationSchema($record->getCollection());
		$field = new RepresentationFieldSchema('name', $record->getCollection(), 'name');
		$schema->addField($field);

		return [$schema, new RepresentationFieldStateItem($field, $record, 'name', $record->getRevision())];
	}
}
