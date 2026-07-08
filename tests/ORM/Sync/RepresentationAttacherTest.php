<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\RepresentationAttacher;
use ON\Data\ORM\Sync\RepresentationAttachmentMode;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationAttacherTest extends TestCase
{
	use OrmFixture;

	public function testAddModeAddsRecordsAndRepresentationState(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);

		$state = $this->attacher()->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => $record],
			$records,
			$representations
		);

		self::assertSame($record, $records->getByStateHash($record->getStateHash()));
		self::assertSame($state, $representations->get($representation));
		self::assertSame($record, $state->getFieldItem('title')->getRecord());
	}

	public function testAddModeRejectsAlreadyTrackedRepresentation(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$attacher = $this->attacher();

		$attacher->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A1'])],
			$records,
			$representations
		);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('already tracked');

		$attacher->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A2'])],
			$records,
			$representations
		);
	}

	public function testReplaceModeReplacesExistingRepresentationState(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$attacher = $this->attacher();
		$first = RecordState::new($this->posts(), ['title' => 'A1']);
		$second = RecordState::new($this->posts(), ['title' => 'A2']);

		$oldState = $attacher->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => $first],
			$records,
			$representations
		);
		$newState = $attacher->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => $second],
			$records,
			$representations,
			RepresentationAttachmentMode::Replace
		);

		self::assertNotSame($oldState, $newState);
		self::assertSame($newState, $representations->get($representation));
		self::assertSame($second, $newState->getFieldItem('title')->getRecord());
	}

	public function testReplaceModeDoesNotRemoveOldStateWhenNewStateCreationFails(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$attacher = $this->attacher();
		$oldState = $attacher->attach(
			$representation,
			$this->postSchema(),
			[RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A1'])],
			$records,
			$representations
		);

		try {
			$attacher->attach(
				$representation,
				$this->postSchema(),
				[RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->users(), ['name' => 'Wrong'])],
				$records,
				$representations,
				RepresentationAttachmentMode::Replace
			);

			self::fail('Expected state creation to fail.');
		} catch (StateException) {
			self::assertSame($oldState, $representations->get($representation));
		}
	}

	public function testDuplicateRecordStatesAreHandledSafely(): void
	{
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$schema = new RepresentationSchema($this->posts());
		$schema->addField(new RepresentationFieldSchema('title', $this->posts(), 'title'));
		$schema->addField(new RepresentationFieldSchema('duplicateTitle', $this->posts(), 'title', sourcePath: ['duplicate']));

		$this->attacher()->attach(
			$representation,
			$schema,
			[
				RepresentationFieldSchema::sourcePathKey([]) => $record,
				RepresentationFieldSchema::sourcePathKey(['duplicate']) => $record,
			],
			$records,
			$representations
		);

		self::assertSame([$record], $records->getAll());
	}

	private function attacher(): RepresentationAttacher
	{
		return new RepresentationAttacher();
	}
}
