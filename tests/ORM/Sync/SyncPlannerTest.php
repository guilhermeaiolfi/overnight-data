<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\Sync\RepresentationValueReader;
use ON\Data\ORM\Sync\SyncConflictDetector;
use ON\Data\ORM\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SyncPlannerTest extends TestCase
{
	public function testNoChangedValuesProducesEmptyPlan(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$plan = $this->planner()->plan($tracked);

		self::assertTrue($plan->isEmpty());
	}

	public function testChangedWritableValueProducesOneUpdate(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = $this->binding(['name' => RecordFieldRef::forState($record, 'name')]);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		$plan = $this->planner()->plan($tracked);

		self::assertFalse($plan->hasConflicts());
		self::assertCount(1, $plan->getUpdates());
		self::assertSame($record, $plan->getUpdates()[0]->getRecord());
		self::assertSame('name', $plan->getUpdates()[0]->getField());
		self::assertSame('A2', $plan->getUpdates()[0]->getValue());
		self::assertSame($binding->getField('name'), $plan->getUpdates()[0]->getBinding());
	}

	public function testPlannerDoesNotMutateRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->planner()->plan($tracked);

		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame(1, $record->getRevision());
	}

	public function testReadOnlyChangedValueDoesNotProduceUpdate(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name'), false));
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		self::assertTrue($this->planner()->plan($tracked)->isEmpty());
	}

	public function testConflictIsIncludedInPlan(): void
	{
		[$record, $tracked] = $this->conflictScenario();

		$plan = $this->planner()->plan($tracked);

		self::assertSame('A2', $record->getValue('name'));
		self::assertCount(1, $plan->getConflicts());
		self::assertSame('name', $plan->getConflicts()[0]->getPath());
	}

	public function testConflictedPathDoesNotProduceUpdate(): void
	{
		[, $tracked] = $this->conflictScenario();

		$plan = $this->planner()->plan($tracked);

		self::assertSame([], $plan->getUpdates());
	}

	public function testStateTargetedFieldRefResolvesWithoutKey(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		self::assertSame($record, $this->planner()->plan($tracked)->getUpdates()[0]->getRecord());
	}

	public function testKeyedFieldRefResolvesThroughRecordStateMap(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$records = new RecordStateMap();
		$records->add($record);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forKey($key, 'name'),
		]), [$key->getHash() => 1]);

		self::assertSame($record, $this->planner($records)->plan($tracked)->getUpdates()[0]->getRecord());
	}

	public function testMissingRecordStateThroughMapThrows(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forKey($key, 'name'),
		]), [$key->getHash() => 1]);

		$this->expectException(StateException::class);
		$this->planner()->plan($tracked);
	}

	public function testDuplicateSameTargetAndSameValueKeepsOneUpdate(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'displayName' => RecordFieldRef::forState($record, 'name'),
		]);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A2']), $binding);

		$updates = $this->planner()->plan($tracked)->getUpdates();

		self::assertCount(1, $updates);
		self::assertSame($binding->getField('name'), $updates[0]->getBinding());
	}

	public function testDuplicateSameTargetAndDifferentValuesThrows(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A3']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'displayName' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('multiple values');
		$this->planner()->plan($tracked);
	}

	public function testMultipleDifferentFieldsOnSameRecordProduceMultipleUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'b@example.test']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'email' => RecordFieldRef::forState($record, 'email'),
		]));

		$updates = $this->planner()->plan($tracked)->getUpdates();

		self::assertCount(2, $updates);
		self::assertSame('name', $updates[0]->getField());
		self::assertSame('email', $updates[1]->getField());
	}

	public function testMultipleDifferentRecordsProduceMultipleUpdates(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->profiles(), ['nickname' => 'N1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'nickname' => 'N2']), $this->binding([
			'name' => RecordFieldRef::forState($first, 'name'),
			'nickname' => RecordFieldRef::forState($second, 'nickname'),
		]));

		$updates = $this->planner()->plan($tracked)->getUpdates();

		self::assertCount(2, $updates);
		self::assertSame($first, $updates[0]->getRecord());
		self::assertSame($second, $updates[1]->getRecord());
	}

	public function testMissingBaselineRevisionThrowsThroughStateException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = $this->binding(['name' => RecordFieldRef::forState($record, 'name')]);
		$tracked = new TrackedRepresentation($this->representation(['name' => 'A2']), $binding, ['other#1' => 1]);

		$this->expectException(StateException::class);
		$this->planner()->plan($tracked);
	}

	public function testCurrentValueEqualToBaselineDoesNotProduceUpdateWhenRecordRevisionChangedElsewhere(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$record->setValue('email', 'b@example.test');

		self::assertTrue($this->planner()->plan($tracked)->isEmpty());
	}

	public function testNullValueIsHandledAsNormalValue(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => null]), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$updates = $this->planner()->plan($tracked)->getUpdates();

		self::assertCount(1, $updates);
		self::assertNull($updates[0]->getValue());
	}

	public function testMissingCurrentPathThrowsThroughReader(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked(new stdClass(), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->expectException(SyncException::class);
		$this->planner()->plan($tracked);
	}

	public function testPlanIncludesConflictsEvenWhenOtherNonConflictingUpdatesExist(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$binding = $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'email' => RecordFieldRef::forState($record, 'email'),
		]);
		$tracked = $this->tracked($this->representation(['name' => 'A3', 'email' => 'b@example.test']), $binding);
		$record->setValue('name', 'A2');

		$plan = $this->planner()->plan($tracked);

		self::assertCount(1, $plan->getConflicts());
		self::assertSame('name', $plan->getConflicts()[0]->getPath());
		self::assertCount(1, $plan->getUpdates());
		self::assertSame('email', $plan->getUpdates()[0]->getField());
	}

	/**
	 * @return array{RecordState, TrackedRepresentation}
	 */
	private function conflictScenario(): array
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$record->setValue('name', 'A2');

		return [$record, $tracked];
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function representation(array $values): stdClass
	{
		$representation = new stdClass();
		foreach ($values as $path => $value) {
			$representation->{$path} = $value;
		}

		return $representation;
	}

	/**
	 * @param array<string, RecordFieldRef> $fields
	 */
	private function binding(array $fields): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		foreach ($fields as $path => $field) {
			$binding->addField(new RepresentationFieldBinding($path, $field));
		}

		return $binding;
	}

	/**
	 * @param array<string, int>|null $baselineRevisions
	 */
	private function tracked(object $representation, RepresentationBinding $binding, ?array $baselineRevisions = null): TrackedRepresentation
	{
		return new TrackedRepresentation(
			$representation,
			$binding,
			$baselineRevisions ?? $this->baselineRevisions($binding)
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function baselineRevisions(RepresentationBinding $binding): array
	{
		$baselineRevisions = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$baselineRevisions[$fieldBinding->getField()->getRecordHash()] = 1;
		}

		return $baselineRevisions;
	}

	private function planner(?RecordStateMap $records = null): SyncPlanner
	{
		return new SyncPlanner(
			new RepresentationValueReader(),
			new SyncConflictDetector(),
			$records ?? new RecordStateMap()
		);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();
	}

	private function profiles(): CollectionInterface
	{
		return (new Registry())
			->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('nickname', 'string')->end();
	}
}
