<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\ScalarRepresentationSynchronizer;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class ScalarRepresentationSynchronizerTest extends TestCase
{
	use OrmFixture;

	public function testSyncReturnsEmptyListWhenThereAreNoRepresentationStates(): void
	{
		self::assertSame([], $this->synchronizer()->sync(new RepresentationStateStore()));
	}

	public function testUnchangedRepresentationStateProducesEmptyPlanAndDoesNotMutateRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

		self::assertCount(1, $plans);
		self::assertTrue($plans[0]->isEmpty());
		self::assertSame(['name' => 'A1'], $record->getValues());
	}

	public function testChangedWritableRepresentationFieldUpdatesTargetRecordState(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

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

		$this->synchronizer()->sync($this->representations($tracked));

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

		$plans = $this->synchronizer()->sync($this->representations($firstTracked, $secondTracked));

		self::assertSame($first, $plans[0]->getUpdates()[0]->getRecord());
		self::assertSame($second, $plans[1]->getUpdates()[0]->getRecord());
		self::assertSame('A2', $first->getValue('name'));
		self::assertSame('B2', $second->getValue('name'));
	}

	public function testConflictingUpdatesAcrossRepresentationsAreRejectedBeforeAnyWrites(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Original']);
		$schema = $this->schema($this->field('name', $record->getCollection(), 'name'));
		$first = $this->tracked($this->representation(['name' => 'Alice']), $schema, $record);
		$second = $this->tracked($this->representation(['name' => 'Bob']), $schema, $record);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('multiple values');

		try {
			$this->synchronizer()->sync($this->representations($first, $second));
		} finally {
			self::assertSame('Original', $record->getValue('name'));
			self::assertSame('Original', $first->getBaseline('name'));
			self::assertSame('Original', $second->getBaseline('name'));
		}
	}

	public function testMatchingUpdatesAcrossRepresentationsRemainIdempotent(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Original']);
		$schema = $this->schema($this->field('name', $record->getCollection(), 'name'));
		$first = $this->tracked($this->representation(['name' => 'Alice']), $schema, $record);
		$second = $this->tracked($this->representation(['name' => 'Alice']), $schema, $record);
		$representations = $this->representations($first, $second);

		$this->synchronizer()->sync($representations);
		$plans = $this->synchronizer()->sync($representations);

		self::assertSame('Alice', $record->getValue('name'));
		self::assertSame('Alice', $first->getBaseline('name'));
		self::assertSame('Alice', $second->getBaseline('name'));
		self::assertTrue($plans[0]->isEmpty());
		self::assertTrue($plans[1]->isEmpty());
	}

	public function testDisjointEditsOnOneRecordRemainStableAcrossRepeatedSyncs(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'Original', 'email' => 'old@example.test']);
		$schema = $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		);
		$first = $this->tracked($this->representation(['name' => 'Alice', 'email' => 'old@example.test']), $schema, $record);
		$second = $this->tracked($this->representation(['name' => 'Original', 'email' => 'new@example.test']), $schema, $record);
		$representations = $this->representations($first, $second);

		$this->synchronizer()->sync($representations);
		$this->synchronizer()->sync($representations);

		self::assertSame(['name' => 'Alice', 'email' => 'new@example.test'], $record->getValues());
	}

	public function testReadOnlySchemasAreNotAppliedAsUpdates(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name', false),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

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
			RepresentationState::fromRecords($schema, ['' => $record])
		);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

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

		$plans = $this->synchronizer()->sync($this->representations($tracked));

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A2', $record->getValue('name'));
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
			$this->synchronizer()->sync($this->representations($tracked));
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
			$this->synchronizer()->sync($this->representations($tracked));
		} finally {
			self::assertSame('A2', $record->getValue('name'));
			self::assertSame('A1', $tracked->getBaseline('name'));
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
			$this->synchronizer()->sync($this->representations($firstTracked, $secondTracked));
		} finally {
			self::assertSame('A1', $first->getValue('name'));
			self::assertSame('B2', $second->getValue('name'));
		}
	}

	public function testSuccessfulSyncRefreshesSyncedBaselineValues(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'b@example.test']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		), $record);

		$this->synchronizer()->sync($this->representations($tracked));

		self::assertSame('A2', $tracked->getBaseline('name'));
		self::assertSame('b@example.test', $tracked->getBaseline('email'));
	}

	public function testUntouchedBaselineValuesArePreserved(): void
	{
		$first = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'old@example.test']);
		$second = RecordState::new($this->profiles(), ['nickname' => 'N1']);
		$schema = $this->schema(
			$this->field('name', $first->getCollection(), 'name'),
			$this->field('email', $first->getCollection(), 'email'),
			new RepresentationFieldSchema('nickname', $second->getCollection(), 'nickname', sourcePath: ['profile']),
		);
		$tracked = $this->tracked($this->representation(['name' => 'A2', 'email' => 'old@example.test', 'nickname' => 'N1']), $schema, $first, $second);
		$first->setValue('email', 'new@example.test');
		$second->setValue('nickname', 'N2');

		$this->synchronizer()->sync($this->representations($tracked));

		self::assertSame('A2', $tracked->getBaseline('name'));
		self::assertSame('old@example.test', $tracked->getBaseline('email'));
		self::assertSame('N1', $tracked->getBaseline('nickname'));
	}

	public function testSyncDoesNotMarkRecordsClean(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => 'A2']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$this->synchronizer()->sync($this->representations($tracked));

		self::assertFalse($record->isClean());
		self::assertTrue($record->isDirty());
	}

	public function testSynchronizerDoesNotDependOnPersistenceClasses(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/ScalarRepresentationSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $source);
		self::assertStringNotContainsString('FlushScheduler', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
	}

	public function testMissingCurrentPathIsOmittedFromThePlan(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'old@example.test']);
		$tracked = $this->tracked($this->representation(['email' => 'new@example.test']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertSame('A1', $record->getValue('name'));
		self::assertSame('new@example.test', $record->getValue('email'));
	}

	public function testInvalidCurrentPathStopsSynchronizationBeforeUpdatesAreApplied(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'old@example.test']);
		$tracked = $this->tracked($this->representation(['author' => 'Ada', 'email' => 'new@example.test']), $this->schema(
			$this->field('author.name', $record->getCollection(), 'name'),
			$this->field('email', $record->getCollection(), 'email'),
		), $record);

		try {
			$this->synchronizer()->sync($this->representations($tracked));
			self::fail('Expected invalid path traversal to fail synchronization.');
		} catch (SyncException $exception) {
			self::assertStringContainsString('author.name', $exception->getMessage());
		}

		self::assertSame('A1', $record->getValue('name'));
		self::assertSame('old@example.test', $record->getValue('email'));
	}

	public function testNullValueIsHandledAsNormalValue(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1']);
		$tracked = $this->tracked($this->representation(['name' => null]), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);

		$plans = $this->synchronizer()->sync($this->representations($tracked));

		self::assertCount(1, $plans[0]->getUpdates());
		self::assertNull($plans[0]->getUpdates()[0]->getValue());
		self::assertNull($record->getValue('name'));
	}

	public function testCurrentValueEqualToBaselineDoesNotProduceUpdateWhenAnotherRecordFieldChanged(): void
	{
		$record = RecordState::new($this->users(), ['name' => 'A1', 'email' => 'a@example.test']);
		$tracked = $this->tracked($this->representation(['name' => 'A1']), $this->schema(
			$this->field('name', $record->getCollection(), 'name'),
		), $record);
		$record->setValue('email', 'b@example.test');

		$plans = $this->synchronizer()->sync($this->representations($tracked));

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
			$this->synchronizer()->sync($this->representations($tracked));
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
		return RepresentationStateObjectRegistry::remember(
			$representation,
			RepresentationState::fromRecords($schema, $this->recordsForSources($schema, $records))
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
