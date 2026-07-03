<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationAdopterTest extends TestCase
{
	public function testAdoptAppliesTemplateBindingToRecordState(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame($record, $tracked->getBinding()->get('title')->getField()->getState());
	}

	public function testAdoptAddsRecordToRecordStateMap(): void
	{
		$records = new RecordStateMap();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$this->adopter($records)->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame($record, $records->getByStateHash($record->getStateHash()));
	}

	public function testAdoptRegistersRepresentationInTrackedRepresentationMap(): void
	{
		$representations = new TrackedRepresentationMap();
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$tracked = $this->adopter(representations: $representations)->adopt($representation, $this->postBinding(), $record);

		self::assertSame($tracked, $representations->get($representation));
	}

	public function testAdoptReturnsCreatedTrackedRepresentation(): void
	{
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$tracked = $this->adopter()->adopt($representation, $this->postBinding(), $record);

		self::assertInstanceOf(TrackedRepresentation::class, $tracked);
		self::assertSame($representation, $tracked->getRepresentation());
	}

	public function testAppliedBindingFieldsTargetConcreteRecordState(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		$field = $tracked->getBinding()->get('title')->getField();

		self::assertFalse($field->isTemplate());
		self::assertSame($record->getStateHash(), $field->getRecordHash());
	}

	public function testBaselineRevisionUsesRecordsCurrentRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$record->setValue('title', 'A2');

		$tracked = $this->adopter()->adopt(new stdClass(), $this->postBinding(), $record);

		self::assertSame([$record->getStateHash() => 2], $tracked->getBaselineRevisions());
	}

	public function testMultipleFieldsForSameRecordProduceOneBaselineRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1', 'body' => 'Body']);
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));
		$binding->add(new RepresentationFieldBinding('body', RecordFieldRef::template($this->posts(), 'body')));

		$tracked = $this->adopter()->adopt(new stdClass(), $binding, $record);

		self::assertSame([$record->getStateHash() => 1], $tracked->getBaselineRevisions());
	}

	public function testReadOnlyFieldsContributeBaselineRecordRevision(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('titleLabel', RecordFieldRef::template($this->posts(), 'title'), false));

		$tracked = $this->adopter()->adopt(new stdClass(), $binding, $record);

		self::assertSame([$record->getStateHash() => 1], $tracked->getBaselineRevisions());
	}

	public function testInputTemplateBindingIsNotMutated(): void
	{
		$template = $this->postBinding();
		$templateField = $template->get('title')->getField();

		$this->adopter()->adopt(new stdClass(), $template, RecordState::new($this->posts(), ['title' => 'A1']));

		self::assertSame($templateField, $template->get('title')->getField());
		self::assertTrue($template->get('title')->getField()->isTemplate());
	}

	public function testAdoptingBindingFromDifferentCollectionThrowsThroughStateValidation(): void
	{
		$this->expectException(StateException::class);

		$this->adopter()->adopt(new stdClass(), $this->postBinding(), RecordState::new($this->users()));
	}

	public function testAdoptingAlreadyTrackedRepresentationThrows(): void
	{
		$representation = new stdClass();
		$records = new RecordStateMap();
		$representations = new TrackedRepresentationMap();
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

	public function testAdoptRelationItemUsesRelatedCollectionsChildBinding(): void
	{
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$collection = new RelatedCollection(RecordState::new($this->users()), 'posts', $this->postBinding());

		$tracked = $this->adopter()->adoptRelationItem($collection, new stdClass(), $record);

		self::assertSame($record, $tracked->getBinding()->get('title')->getField()->getState());
	}

	public function testAdoptRelationItemDoesNotAddItemToCollection(): void
	{
		$item = new stdClass();
		$collection = new RelatedCollection(
			RecordState::new($this->users()),
			'posts',
			$this->postBinding(),
			RelationCollectionState::UNLOADED
		);

		$this->adopter()->adoptRelationItem($collection, $item, RecordState::new($this->posts(), ['title' => 'A1']));

		self::assertFalse($collection->contains($item));
		self::assertSame([], $collection->getAdded());
		self::assertFalse($collection->hasChanges());
	}

	public function testAdoptRelationItemRegistersItemAsTracked(): void
	{
		$representations = new TrackedRepresentationMap();
		$item = new stdClass();
		$collection = new RelatedCollection(RecordState::new($this->users()), 'posts', $this->postBinding());

		$tracked = $this->adopter(representations: $representations)
			->adoptRelationItem($collection, $item, RecordState::new($this->posts(), ['title' => 'A1']));

		self::assertSame($tracked, $representations->get($item));
	}

	private function adopter(
		?RecordStateMap $records = null,
		?TrackedRepresentationMap $representations = null,
	): RepresentationAdopter {
		return new RepresentationAdopter(
			$records ?? new RecordStateMap(),
			$representations ?? new TrackedRepresentationMap()
		);
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('body', 'string')->end();
	}
}
