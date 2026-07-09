<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class SessionAdoptTest extends TestCase
{
	use OrmFixture;

	public function testAddModeAddsRecordsAndRepresentationState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$state = RepresentationState::fromRecords($this->postSchema(), [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		$adopted = $session->adopt($representation, $state);

		self::assertSame($record, $session->getRecords()->getByStateHash($record->getStateHash()));
		self::assertSame($adopted, $session->getRepresentations()->get($representation));
		self::assertSame($record, $adopted->getFieldItem('title')->getRecord());
	}

	public function testAddModeRejectsAlreadyTrackedRepresentation(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$schema = $this->postSchema();

		$session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A1']),
			]),
		);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('Cannot attach representation because it is already tracked.');

		$session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A2']),
			]),
		);
	}

	public function testReplaceModeReplacesExistingRepresentationState(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$schema = $this->postSchema();
		$first = RecordState::new($this->posts(), ['title' => 'A1']);
		$second = RecordState::new($this->posts(), ['title' => 'A2']);

		$oldState = $session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => $first,
			]),
		);
		$newState = $session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => $second,
			]),
			RepresentationAttachmentMode::Replace,
		);

		self::assertNotSame($oldState, $newState);
		self::assertSame($newState, $session->getRepresentations()->get($representation));
		self::assertSame($second, $newState->getFieldItem('title')->getRecord());
	}

	public function testReplaceModeDoesNotRemoveOldStateWhenNewStateWasNeverAdopted(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$schema = $this->postSchema();
		$oldState = $session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->posts(), ['title' => 'A1']),
			]),
		);

		try {
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => RecordState::new($this->users(), ['name' => 'Wrong']),
			]);
			self::fail('Expected state creation to fail.');
		} catch (StateException) {
			self::assertSame($oldState, $session->getRepresentations()->get($representation));
		}
	}

	public function testDuplicateRecordReferencesInsideStateAreAddedSafely(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$record = RecordState::new($this->posts(), ['title' => 'A1']);
		$schema = new RepresentationSchema($this->posts());
		$schema->addField(new RepresentationFieldSchema('title', $this->posts(), 'title'));
		$schema->addField(new RepresentationFieldSchema('duplicateTitle', $this->posts(), 'title', sourcePath: ['duplicate']));

		$session->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => $record,
				RepresentationFieldSchema::sourcePathKey(['duplicate']) => $record,
			]),
		);

		self::assertSame([$record], $session->getRecords()->getAll());
	}
}
