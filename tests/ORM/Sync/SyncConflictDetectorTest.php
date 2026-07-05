<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\SyncConflictDetector;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class SyncConflictDetectorTest extends TestCase
{
	use OrmFixture;

	public function testA1A2A3CaseReturnsConflict(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$rep1 = $this->tracked($field, 1);
		$rep2 = $this->tracked($field, 1);
		$detector = new SyncConflictDetector();

		self::assertSame([], $detector->detect($rep2, ['name' => 'A2'], static fn () => $record));

		$record->setValue('name', 'A2');
		$conflicts = $detector->detect($rep1, ['name' => 'A3'], static fn () => $record);

		self::assertCount(1, $conflicts);
		self::assertSame('name', $conflicts[0]->getPath());
		self::assertSame('A1', $conflicts[0]->getBaselineValue());
		self::assertSame('A2', $conflicts[0]->getRecordValue());
		self::assertSame('A3', $conflicts[0]->getRepresentationValue());
	}

	public function testUnchangedRepresentationPathHasNoConflict(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A1'], static fn () => $record));
	}

	public function testChangedPathHasNoConflictWhenRecordRevisionIsUnchanged(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$tracked = $this->tracked($field, 1);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2'], static fn () => $record));
	}

	public function testChangedPathHasNoConflictWhenRecordChangedButFieldStillEqualsBaselineValue(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);
		$record = RecordState::clean($key, ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($field, 1);
		$record->setValue('email', 'b@example.test');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2'], static fn () => $record));
	}

	public function testChangedPathHasNoConflictWhenRepresentationAlreadyEqualsCurrentRecordValue(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A2'], static fn () => $record));
	}

	public function testReadOnlyBindingIsIgnored(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', new RecordFieldRef($users, 'name', $key), false));
		$tracked = new RepresentationState($binding, [$key->getHash() => 1]);

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, [], static fn () => null));
	}

	public function testMissingCurrentValueThrows(): void
	{
		[$record, $tracked] = $this->changedRecordScenario('A2');

		$this->expectException(SyncException::class);
		(new SyncConflictDetector())->detect($tracked, [], static fn () => $record);
	}

	public function testMissingHistoryRevisionThrowsClearException(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$tracked = $this->tracked($field, 99);

		$this->expectException(StateException::class);
		(new SyncConflictDetector())->detect($tracked, ['name' => 'A2'], static fn () => $record);
	}

	public function testDetectorUsesStateTargetedRefWithoutCallingResolver(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$field = RecordFieldRef::forState($record, 'name');
		$tracked = $this->tracked($field, 1);

		self::assertSame([], (new SyncConflictDetector())->detect(
			$tracked,
			['name' => 'A2'],
			static function (): never {
				self::fail('Resolver should not be called for state-targeted refs.');
			}
		));
	}

	public function testDetectorStillUsesResolverForKeyedRef(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = RecordFieldRef::forKey($key, 'name');
		$record = RecordState::clean($key, ['name' => 'A1']);
		$tracked = $this->tracked($field, 1);
		$resolverCalled = false;

		self::assertSame([], (new SyncConflictDetector())->detect(
			$tracked,
			['name' => 'A2'],
			static function () use ($record, &$resolverCalled): RecordState {
				$resolverCalled = true;

				return $record;
			}
		));
		self::assertTrue($resolverCalled);
	}

	public function testDetectorCanResolveKeyedRefThroughRecordStateStore(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = RecordFieldRef::forKey($key, 'name');
		$record = RecordState::clean($key, ['name' => 'A1']);
		$rep1 = $this->tracked($field, 1);
		$rep2 = $this->tracked($field, 1);
		$stateMap = new RecordStateStore();
		$stateMap->add($record);
		$detector = new SyncConflictDetector();

		self::assertSame([], $detector->detect($rep2, ['name' => 'A2'], $stateMap->requireForField(...)));

		$record->setValue('name', 'A2');
		$conflicts = $detector->detect($rep1, ['name' => 'A3'], $stateMap->requireForField(...));

		self::assertCount(1, $conflicts);
		self::assertSame('name', $conflicts[0]->getPath());
		self::assertSame('A1', $conflicts[0]->getBaselineValue());
		self::assertSame('A2', $conflicts[0]->getRecordValue());
		self::assertSame('A3', $conflicts[0]->getRepresentationValue());
	}

	public function testA1A2A3CaseReturnsConflictForNewStateTargetedRecord(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$field = RecordFieldRef::forState($record, 'name');
		$rep1 = $this->tracked($field, 1);
		$rep2 = $this->tracked($field, 1);
		$detector = new SyncConflictDetector();

		self::assertSame([], $detector->detect($rep2, ['name' => 'A2'], static fn () => null));

		$record->setValue('name', 'A2');
		$conflicts = $detector->detect($rep1, ['name' => 'A3'], static fn () => null);

		self::assertCount(1, $conflicts);
		self::assertSame('name', $conflicts[0]->getPath());
		self::assertSame('A1', $conflicts[0]->getBaselineValue());
		self::assertSame('A2', $conflicts[0]->getRecordValue());
		self::assertSame('A3', $conflicts[0]->getRepresentationValue());
	}

	public function testUnchangedStateTargetedPathHasNoConflict(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$field = RecordFieldRef::forState($record, 'name');
		$tracked = $this->tracked($field, 1);
		$record->setValue('name', 'A2');

		self::assertSame([], (new SyncConflictDetector())->detect($tracked, ['name' => 'A1'], static fn () => null));
	}

	/**
	 * @return array{RecordState, RepresentationState}
	 */
	private function changedRecordScenario(string $recordName): array
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$field = new RecordFieldRef($users, 'name', $key);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$record->setValue('name', $recordName);

		return [$record, $this->tracked($field, 1)];
	}

	private function tracked(RecordFieldRef $field, int $revision): RepresentationState
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', $field));

		return new RepresentationState($binding, [$field->getRecordHash() => $revision]);
	}
}
