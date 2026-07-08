<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\RepresentationAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationAdopterTest extends TestCase
{
	use OrmFixture;

	public function testAdoptAppliesTemplateBindingToRecordState(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame($record, $tracked->getFieldItem('title')->getRecord());
	}

	public function testAdoptAddsRecordToRecordStateStore(): void
	{
		$records = new RecordStateStore();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$this->adopter($records)->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame($record, $records->getByStateHash($record->getStateHash()));
	}

	public function testAdoptRegistersRepresentationInRepresentationStateStore(): void
	{
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$tracked = $this->adopter(representations: $representations)->adopt($representation, $this->postBinding(), $record);

		self::assertSame($tracked, $representations->get($representation));
	}

	public function testAdoptReturnsCreatedRepresentationState(): void
	{
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$representations = new RepresentationStateStore();

		$tracked = $this->adopter(representations: $representations)->adopt($representation, $this->postBinding(), $record);

		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertSame($tracked, $representations->get($representation));
	}

	public function testAppliedBindingFieldsTargetConcreteRecordState(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		$item = $tracked->getFieldItem('title');

		self::assertSame($record, $item->getRecord());
		self::assertSame($record->getStateHash(), $item->getRecord()->getStateHash());
	}

	public function testBaselineRevisionUsesRecordsCurrentRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$record->setValue('title', 'A2');

		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame(2, $tracked->getFieldItem('title')->getBaselineRevision());
	}

	public function testMultipleFieldsForSameRecordProduceOneBaselineRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1', 'body' => 'Body']);
		$binding = new RepresentationSchema($this->posts());
		$binding->addField(new RepresentationFieldSchema('title', $this->posts(), 'title'));
		$binding->addField(new RepresentationFieldSchema('body', $this->posts(), 'body'));

		$tracked = $this->adopter()->adopt(new stdClass(), $binding, $record);

		self::assertSame(1, $tracked->getFieldItem('title')->getBaselineRevision());
		self::assertSame(1, $tracked->getFieldItem('body')->getBaselineRevision());
		self::assertSame($tracked->getFieldItem('title')->getRecord(), $tracked->getFieldItem('body')->getRecord());
	}

	public function testReadOnlyFieldsContributeBaselineRecordRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$binding = new RepresentationSchema($this->posts());
		$binding->addField(new RepresentationFieldSchema('titleLabel', $this->posts(), 'title', false));

		$tracked = $this->adopter()->adopt(new stdClass(), $binding, $record);

		self::assertSame(1, $tracked->getFieldItem('titleLabel')->getBaselineRevision());
		self::assertSame($record, $tracked->getFieldItem('titleLabel')->getRecord());
	}

	public function testInputTemplateBindingIsNotMutated(): void
	{
		$template = $this->postBinding();
		$templateField = $template->getField('title');

		$this->adopter()->adopt(new stdClass(), $template, RecordState::new($this->posts(), ['title' => 'A1']));

		self::assertSame($templateField, $template->getField('title'));
		self::assertSame('posts', $template->getField('title')->getCollectionName());
	}

	public function testAdoptingBindingFromDifferentCollectionThrowsThroughStateValidation(): void
	{
		$this->expectException(StateException::class);

		$this->adopter()->adopt(new stdClass(), $this->postBinding(), RecordState::new($this->users()));
	}

	public function testAdoptingAlreadyRepresentationStateThrows(): void
	{
		$representation = new stdClass();
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$adopter = $this->adopter($records, $representations);

		$adopter->adopt($representation, $this->postBinding(), RecordState::new($this->posts(), ['title' => 'A1']));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('already tracked');
		$adopter->adopt($representation, $this->postBinding(), RecordState::new($this->posts(), ['title' => 'A2']));
	}

	public function testAdoptingSameRepresentationTwiceThrowsInsteadOfComparingContexts(): void
	{
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$adopter = $this->adopter();

		$adopter->adopt($representation, $this->postBinding(), $record);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('already tracked');
		$adopter->adopt($representation, $this->postBinding(), $record);
	}

	public function testRelationItemCanBeAdoptedThroughPlainAdoptWithChildBinding(): void
	{
		$representations = new RepresentationStateStore();
		$item = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$collection = new ToManyRelationState(RecordState::new($this->users()), 'posts', $this->postBinding());

		$collection->add($item);
		$tracked = $this->adopter(representations: $representations)->adopt(
			$item,
			$collection->getChildBinding(),
			$record
		);

		self::assertSame($record, $tracked->getFieldItem('title')->getRecord());
		self::assertSame($tracked, $representations->get($item));
		self::assertSame([$item], $collection->getAdded());
	}

	private function adopter(
		?RecordStateStore $records = null,
		?RepresentationStateStore $representations = null,
	): RepresentationAdopter {
		return new RepresentationAdopter(
			$records ?? new RecordStateStore(),
			$representations ?? new RepresentationStateStore()
		);
	}
}
