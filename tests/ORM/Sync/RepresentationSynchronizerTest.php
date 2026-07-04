<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationExpressionBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationSynchronizer;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationSynchronizerTest extends TestCase
{
	public function testSyncReturnsEmptyListWhenThereAreNoTrackedRepresentations(): void
	{
		self::assertSame([], $this->synchronizer()->sync(new TrackedRepresentationMap(), new RecordStateMap()));
	}

	public function testUnchangedTrackedRepresentationProducesEmptyPlanAndDoesNotMutateRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$plans = $this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertCount(1, $plans);
		self::assertTrue($plans[0]->isEmpty());
		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame(1, $record->getRevision());
	}

	public function testChangedWritableRepresentationFieldUpdatesTargetRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$plans = $this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testChangedRepresentationFieldMakesCleanRecordStateDirty(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertTrue($record->isDirty());
		self::assertSame(['name' => 'A2'], $record->getDirtyValues());
	}

	public function testMultipleTrackedRepresentationsAreSynchronizedInInsertionOrder(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstTracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($first, 'name'),
		]));
		$secondTracked = $this->tracked($this->representation(['name' => 'B2']), $this->binding([
			'name' => RecordFieldRef::forState($second, 'name'),
		]));

		$plans = $this->synchronizer()->sync($this->trackedMap($firstTracked, $secondTracked), new RecordStateMap());

		self::assertSame($first, $plans[0]->getUpdates()[0]->getRecord());
		self::assertSame($second, $plans[1]->getUpdates()[0]->getRecord());
		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B2', $second->getValue('name'));
	}

	public function testReadOnlyBindingsAreNotAppliedAsUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name'), false));
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		$plans = $this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertTrue($plans[0]->isEmpty());
		self::assertSame('A1', $record->getValue('name'));
	}

	public function testSynchronizerIgnoresExpressionAndRelationBindingsWhileSyncingScalarFields(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));
		$binding->addExpression(new RepresentationExpressionBinding('postCount', 'post_count'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			'posts',
			RepresentationRelationCardinality::MANY,
			new RepresentationBinding(),
			RelationCollectionState::FULLY_LOADED
		));
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		$plans = $this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithSameValueApplyOnlyOnce(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'displayName' => RecordFieldRef::forState($record, 'name'),
		]));

		$plans = $this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
		self::assertSame(2, $record->getRevision());
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithDifferentValuesThrowSyncException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A3']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'displayName' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('multiple values');

		try {
			$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());
		} finally {
			self::assertSame('A1', $record->getValue('name'));
		}
	}

	public function testConflictsThrowSyncExceptionBeforeApplyingUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));
		$record->setValue('name', 'A2');

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('1 conflict');
		$this->expectExceptionMessage('name');

		try {
			$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());
		} finally {
			self::assertSame('A2', $record->getValue('name'));
			self::assertSame([$record->getStateHash() => 1], $tracked->getBaselineRevisions());
		}
	}

	public function testConflictInOneRepresentationPreventsAllRepresentationUpdates(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstTracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($first, 'name'),
		]));
		$secondTracked = $this->tracked($this->representation(['name' => 'B3']), $this->binding([
			'name' => RecordFieldRef::forState($second, 'name'),
		]));
		$second->setValue('name', 'B2');

		$this->expectException(SyncException::class);

		try {
			$this->synchronizer()->sync($this->trackedMap($firstTracked, $secondTracked), new RecordStateMap());
		} finally {
			self::assertSame('A1', $first->getValue('name'));
			self::assertSame('B2', $second->getValue('name'));
		}
	}

	public function testSuccessfulSyncRefreshesTouchedBaselineRevisionsToCurrentRecordRevisions(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'b@example.test']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
			'email' => RecordFieldRef::forState($record, 'email'),
		]));

		$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertSame([$record->getStateHash() => $record->getRevision()], $tracked->getBaselineRevisions());
		self::assertSame(3, $record->getRevision());
	}

	public function testUntouchedBaselineRevisionsArePreserved(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->profiles(), ['nickname' => 'N1']);
		$binding = $this->binding([
			'name' => RecordFieldRef::forState($first, 'name'),
			'nickname' => RecordFieldRef::forState($second, 'nickname'),
		]);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'nickname' => 'N1']), $binding);
		$second->setValue('nickname', 'N2');

		$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertSame([
			$first->getStateHash() => $first->getRevision(),
			$second->getStateHash() => 1,
		], $tracked->getBaselineRevisions());
	}

	public function testSyncDoesNotMarkRecordsClean(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forState($record, 'name'),
		]));

		$this->synchronizer()->sync($this->trackedMap($tracked), new RecordStateMap());

		self::assertFalse($record->isClean());
		self::assertTrue($record->isDirty());
	}

	public function testSynchronizerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/RepresentationSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
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

	private function trackedMap(TrackedRepresentation ...$trackedRepresentations): TrackedRepresentationMap
	{
		$map = new TrackedRepresentationMap();
		foreach ($trackedRepresentations as $tracked) {
			$map->add($tracked);
		}

		return $map;
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

	private function synchronizer(): RepresentationSynchronizer
	{
		return new RepresentationSynchronizer();
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
