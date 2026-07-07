<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationExpressionBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\ScalarRepresentationSynchronizer;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class ScalarRepresentationSynchronizerTest extends TestCase
{
	use OrmFixture;

	public function testSyncReturnsEmptyListWhenThereAreNoRepresentationStates(): void
	{
		self::assertSame([], $this->synchronizer()->sync(new RepresentationStore(), new RecordStateStore()));
	}

	public function testUnchangedRepresentationStateProducesEmptyPlanAndDoesNotMutateRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans);
		self::assertTrue($plans[0]->isEmpty());
		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame(1, $record->getRevision());
	}

	public function testChangedWritableRepresentationFieldUpdatesTargetRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testChangedRepresentationFieldMakesCleanRecordStateDirty(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($record->isDirty());
		self::assertSame(['name' => 'A2'], $record->getDirtyValues());
	}

	public function testMultipleRepresentationStatesAreSynchronizedInInsertionOrder(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstTracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => $first->getCollection(), 'name',
		]));
		$secondTracked = $this->tracked($this->representation(['name' => 'B2']), $this->binding([
			'name' => $second->getCollection(), 'name',
		]));

		$plans = $this->synchronizer()->sync($this->representations($firstTracked, $secondTracked), new RecordStateStore());

		self::assertSame($first, $plans[0]->getUpdates()[0]->getRecord());
		self::assertSame($second, $plans[1]->getUpdates()[0]->getRecord());
		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B2', $second->getValue('name'));
	}

	public function testReadOnlyBindingsAreNotAppliedAsUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', $record->getCollection(), 'name', false));
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($plans[0]->isEmpty());
		self::assertSame('A1', $record->getValue('name'));
	}

	public function testSynchronizerIgnoresExpressionAndRelationBindingsWhileSyncingScalarFields(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', $record->getCollection(), 'name'));
		$binding->addExpression(new RepresentationExpressionBinding('postCount', 'post_count'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$record->getCollection(), 'posts',
			new RepresentationBinding(),
			true
		));
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $binding);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithSameValueApplyOnlyOnce(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A2']), $this->binding([
			'name' => $record->getCollection(), 'name',
			'displayName' => $record->getCollection(), 'name',
		]));

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
		self::assertSame(2, $record->getRevision());
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithDifferentValuesThrowSyncException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A3']), $this->binding([
			'name' => $record->getCollection(), 'name',
			'displayName' => $record->getCollection(), 'name',
		]));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('multiple values');

		try {
			$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
		} finally {
			self::assertSame('A1', $record->getValue('name'));
		}
	}

	public function testConflictsThrowSyncExceptionBeforeApplyingUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));
		$record->setValue('name', 'A2');

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('1 conflict');
		$this->expectExceptionMessage('name');

		try {
			$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
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
			'name' => $first->getCollection(), 'name',
		]));
		$secondTracked = $this->tracked($this->representation(['name' => 'B3']), $this->binding([
			'name' => $second->getCollection(), 'name',
		]));
		$second->setValue('name', 'B2');

		$this->expectException(SyncException::class);

		try {
			$this->synchronizer()->sync($this->representations($firstTracked, $secondTracked), new RecordStateStore());
		} finally {
			self::assertSame('A1', $first->getValue('name'));
			self::assertSame('B2', $second->getValue('name'));
		}
	}

	public function testSuccessfulSyncRefreshesTouchedBaselineRevisionsToCurrentRecordRevisions(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'b@example.test']), $this->binding([
			'name' => $record->getCollection(), 'name',
			'email' => $record->getCollection(), 'email',
		]));

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertSame([$record->getStateHash() => $record->getRevision()], $tracked->getBaselineRevisions());
		self::assertSame(3, $record->getRevision());
	}

	public function testUntouchedBaselineRevisionsArePreserved(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->profiles(), ['nickname' => 'N1']);
		$binding = $this->binding([
			'name' => $first->getCollection(), 'name',
			'nickname' => $second->getCollection(), 'nickname',
		]);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'nickname' => 'N1']), $binding);
		$second->setValue('nickname', 'N2');

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

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
			'name' => $record->getCollection(), 'name',
		]));

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertFalse($record->isClean());
		self::assertTrue($record->isDirty());
	}

	public function testSynchronizerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/ScalarRepresentationSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
	}

	public function testMissingCurrentPathThrowsSyncException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked(new stdClass(), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));

		$this->expectException(SyncException::class);

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
	}

	public function testKeyedFieldRefResolvesThroughRecordStateStore(): void
	{
		$users = $this->users();
		$key = $users->getKey(10);
		$record = RecordState::clean($key, ['name' => 'A1']);
		$records = new RecordStateStore();
		$records->add($record);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->binding([
			'name' => RecordFieldRef::forKey($key, 'name'),
		]), [$key->getHash() => 1]);

		$plans = $this->synchronizer()->sync($this->representations($tracked), $records);

		self::assertSame($record, $plans[0]->getUpdates()[0]->getRecord());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testNullValueIsHandledAsNormalValue(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => null]), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertNull($plans[0]->getUpdates()[0]->getValue());
		self::assertNull($record->getValue('name'));
	}

	public function testCurrentValueEqualToBaselineDoesNotProduceUpdateWhenRecordRevisionChangedElsewhere(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->binding([
			'name' => $record->getCollection(), 'name',
		]));
		$record->setValue('email', 'b@example.test');

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($plans[0]->isEmpty());
	}

	public function testConflictedPathStillPlansUpdatesForOtherNonConflictingFields(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$binding = $this->binding([
			'name' => $record->getCollection(), 'name',
			'email' => $record->getCollection(), 'email',
		]);
		$tracked = $this->tracked($this->representation(['name' => 'A3', 'email' => 'b@example.test']), $binding);
		$record->setValue('name', 'A2');

		$this->expectException(SyncException::class);

		try {
			$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
		} finally {
			self::assertSame('a@example.test', $record->getValue('email'));
		}
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
	private function tracked(object $representation, RepresentationBinding $binding, ?array $baselineRevisions = null): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember($representation, new RepresentationState(
			$binding,
			$baselineRevisions ?? $this->baselineRevisions($binding)
		));
	}

	private function synchronizer(): ScalarRepresentationSynchronizer
	{
		return new ScalarRepresentationSynchronizer();
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
