<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\State\RepresentationRelationStateItem;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\ScalarRepresentationSynchronizer;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class ScalarRepresentationSynchronizerTest extends TestCase
{
	use OrmFixture;

	public function testSyncReturnsEmptyListWhenThereAreNoRepresentationStates(): void
	{
		self::assertSame([], $this->synchronizer()->sync(new RepresentationStateStore(), new RecordStateStore()));
	}

	public function testUnchangedRepresentationStateProducesEmptyPlanAndDoesNotMutateRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans);
		self::assertTrue($plans[0]->isEmpty());
		self::assertSame(['name' => 'A1'], $record->getValues());
		self::assertSame(1, $record->getRevision());
	}

	public function testChangedWritableRepresentationFieldUpdatesTargetRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testChangedRepresentationFieldMakesCleanRecordStateDirty(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($record->isDirty());
		self::assertSame(['name' => 'A2'], $record->getDirtyValues());
	}

	public function testMultipleRepresentationStatesAreSynchronizedInInsertionOrder(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstTracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $first->getCollection(), 'name'),
		), $first);
		$secondTracked = $this->tracked($this->representation(['name' => 'B2']), $this->schema(
			$this->field('name', $second->getCollection(), 'name'),
		), $second);

		$plans = $this->synchronizer()->sync($this->representations($firstTracked, $secondTracked), new RecordStateStore());

		self::assertSame($first, $plans[0]->getUpdates()[0]->getRecord());
		self::assertSame($second, $plans[1]->getUpdates()[0]->getRecord());
		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B2', $second->getValue('name'));
	}

	public function testReadOnlySchemasAreNotAppliedAsUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name', false),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($plans[0]->isEmpty());
		self::assertSame('A1', $record->getValue('name'));
	}

	public function testSynchronizerIgnoresRelationSchemasWhileSyncingScalarFields(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$nameField = $this->field('name', $record->getCollection(), 'name');
		$schema = $this->schema($nameField);
		$relationSchema = new RepresentationRelationSchema(
			'posts',
			$record->getCollection(),
			'posts',
			$this->postSchema(),
			true
		);
		$schema->addRelation($relationSchema);
		$tracked = RepresentationStateObjectRegistry::remember(
			$this->representation(['name' => 'A2']),
			new RepresentationState(
				$schema,
				[new RepresentationFieldStateItem($nameField, $record, 'name', $record->getRevision())],
				[new RepresentationRelationStateItem($relationSchema, $record, 'posts')],
			)
		);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithSameValueApplyOnlyOnce(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('displayName', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
		self::assertSame(2, $record->getRevision());
	}

	public function testDuplicateWritablePathsTargetingSameFieldWithDifferentValuesThrowSyncException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'displayName' => 'A3']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('displayName', $record->getCollection(), 'name'),
		), $record);

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
		$tracked = $this->tracked($this->representation(['name' => 'A3']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);
		$record->setValue('name', 'A2');

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('1 conflict');
		$this->expectExceptionMessage('name');

		try {
			$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
		} finally {
			self::assertSame('A2', $record->getValue('name'));
			self::assertSame(1, $tracked->getFieldItem('name')->getBaselineRevision());
		}
	}

	public function testConflictInOneRepresentationPreventsAllRepresentationUpdates(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->users(), ['name' => 'B1']);
		$firstTracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $first->getCollection(), 'name'),
		), $first);
		$secondTracked = $this->tracked($this->representation(['name' => 'B3']), $this->schema(
			$this->field('name', $second->getCollection(), 'name'),
		), $second);
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
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'b@example.test']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		), $record);

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertSame($record->getRevision(), $tracked->getFieldItem('name')->getBaselineRevision());
		self::assertSame($record->getRevision(), $tracked->getFieldItem('email')->getBaselineRevision());
		self::assertSame(3, $record->getRevision());
	}

	public function testUntouchedBaselineRevisionsArePreserved(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1']);
		$second = RecordState::new($this->profiles(), ['nickname' => 'N1']);
		$schema = $this->schema(
			$this->field('name', $first->getCollection(), 'name'),
			$this->field('nickname', $second->getCollection(), 'nickname'),
		);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'nickname' => 'N1']), $schema, $first, $second);
		$second->setValue('nickname', 'N2');

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertSame($first->getRevision(), $tracked->getFieldItem('name')->getBaselineRevision());
		self::assertSame(1, $tracked->getFieldItem('nickname')->getBaselineRevision());
	}

	public function testSyncDoesNotMarkRecordsClean(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertFalse($record->isClean());
		self::assertTrue($record->isDirty());
	}

	public function testSynchronizerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/ScalarRepresentationSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
	}

	public function testMissingCurrentPathThrowsSyncException(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked(new stdClass(), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$this->expectException(SyncException::class);

		$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
	}

	public function testNullValueIsHandledAsNormalValue(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => null]), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertNull($plans[0]->getUpdates()[0]->getValue());
		self::assertNull($record->getValue('name'));
	}

	public function testCurrentValueEqualToBaselineDoesNotProduceUpdateWhenRecordRevisionChangedElsewhere(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);
		$record->setValue('email', 'b@example.test');

		$plans = $this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());

		self::assertTrue($plans[0]->isEmpty());
	}

	public function testConflictedPathStillPlansUpdatesForOtherNonConflictingFields(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$schema = $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		);
		$tracked = $this->tracked($this->representation(['name' => 'A3', 'email' => 'b@example.test']), $schema, $record);
		$record->setValue('name', 'A2');

		$this->expectException(SyncException::class);

		try {
			$this->synchronizer()->sync($this->representations($tracked), new RecordStateStore());
		} finally {
			self::assertSame('a@example.test', $record->getValue('email'));
		}
	}

	private function field(
		string $path,
		CollectionInterface $collection,
		string $fieldName,
		bool $writable = true,
	): RepresentationFieldSchema {
		return new RepresentationFieldSchema($path, $collection, $fieldName, $writable);
	}

	private function schema(RepresentationFieldSchema ...$fields): RepresentationSchema
	{
		$schema = new RepresentationSchema($fields[0]->getCollection());
		foreach ($fields as $field) {
			$schema->addField($field);
		}

		return $schema;
	}

	private function tracked(object $representation, RepresentationSchema $schema, RecordState ...$records): RepresentationState
	{
		$items = [];
		foreach ($schema->getFields() as $fieldSchema) {
			foreach ($records as $record) {
				if ($record->getCollection()->getName() !== $fieldSchema->getCollectionName()) {
					continue;
				}

				$items[] = new RepresentationFieldStateItem($fieldSchema, $record, $fieldSchema->getFieldName(), $record->getRevision());

				break;
			}
		}

		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($schema, $items)
		);
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
