<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\State\Query\QueryResultHydrator;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\SessionContext;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationFieldRole;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class QueryResultHydratorTest extends TestCase
{
	public function testHydrationIgnoresStagedCreateWithoutConsumingIt(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$session = new Session(new RecordingCommandExecutor());
		$session->create($object);

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		self::assertTrue($state->requireRootRecord()->isClean());
		self::assertTrue($state->getRecordForField($schema->getField('name'))->isClean());
		self::assertTrue($session->getContext()->getIntents()->get($object)?->isCreate());
	}

	public function testBuildsFlatObjectWithFieldsFromTwoCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		self::assertTrue($session->getRepresentations()->has($object));
		self::assertSame($state, $session->getRepresentations()->get($object));
		self::assertTrue($state->getSchema()->hasField('id'));
		self::assertTrue($state->getSchema()->hasField('name'));
	}

	public function testCreatesOneRecordStatePerCollection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $session->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		self::assertNotSame($userRecord, $companyRecord);
	}

	public function testRepresentationStateBindsFieldsToBothCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($this->flatUser(1, 'Acme'), $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		$idField = $state->getSchema()->getField('id');
		$nameField = $state->getSchema()->getField('name');

		self::assertSame('users', $state->getRecordForField($idField)->getCollection()->getName());
		self::assertSame('companies', $state->getRecordForField($nameField)->getCollection()->getName());
		self::assertNotSame($state->getRecordForField($idField)->getStateHash(), $state->getRecordForField($nameField)->getStateHash());
	}

	public function testBaselinesCaptureValuesFromBothRecords(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($this->flatUser(1, 'Acme'), $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $session->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		$idField = $state->getSchema()->getField('id');
		$nameField = $state->getSchema()->getField('name');
		self::assertSame($userRecord->getStateHash(), $state->getRecordForField($idField)->getStateHash());
		self::assertSame($companyRecord->getStateHash(), $state->getRecordForField($nameField)->getStateHash());
		self::assertSame(1, $state->getBaseline('id'));
		self::assertSame('Acme', $state->getBaseline('name'));
	}

	public function testHiddenIdentityValuesAreReadFromAssembledRow(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$session = new Session(new RecordingCommandExecutor());

		$this->adoptFlatProjection($object, $schema, [
			'company.id' => 5,
		], $session);

		$companyRecord = $session->getRecords()->getByKey($companies->getKey(5));
		self::assertInstanceOf(RecordState::class, $companyRecord);
	}

	public function testVisibleIdentityValuesCanComeFromObjectProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$session = new Session(new RecordingCommandExecutor());

		$this->adoptFlatProjection($object, $schema, [
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		self::assertInstanceOf(RecordState::class, $userRecord);
	}

	public function testSourceRowValuesTakePrecedenceOverVisibleObjectProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$this->adoptFlatProjection($this->flatUser(99, 'Object value'), $schema, [
			'id' => 1,
			'name' => 'Source row value',
			'company.id' => 5,
		], $session);

		self::assertInstanceOf(RecordState::class, $session->getRecords()->getByKey($users->getKey(1)));
		self::assertNull($session->getRecords()->getByKey($users->getKey(99)));
		self::assertSame('Source row value', $session->getRecords()->getByKey($companies->getKey(5))?->getValue('name'));
	}

	public function testHydratePreservesExplicitNullAndOmitsMissingValuesFromInitialSnapshot(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$schema->addField(new RepresentationFieldSchema('company_id', $users, 'company_id'));
		$session = new Session(new RecordingCommandExecutor());

		$this->adoptFlatProjection($this->flatUser(1, 'ignored'), $schema, [
			'id' => 1,
			'name' => null,
			'company.id' => 5,
		], $session);

		$company = $session->getRecords()->getByKey($companies->getKey(5));
		self::assertInstanceOf(RecordState::class, $company);
		self::assertTrue(array_key_exists('name', $company->getValues()));
		self::assertNull($company->getValue('name'));
		$user = $session->getRecords()->getByKey($users->getKey(1));
		self::assertInstanceOf(RecordState::class, $user);
		self::assertArrayNotHasKey('company_id', $user->getValues());
	}

	public function testExplicitNullSourceRowIdentityDoesNotFallBackToVisibleObjectProperty(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('results without identity are never tracked');

		$this->adoptFlatProjection($this->flatUser(99, 'Acme'), $schema, [
			'id' => null,
			'name' => 'Acme',
			'company.id' => 5,
		], new Session(new RecordingCommandExecutor()));
	}

	public function testThrowsWhenMapEntryIsMissingAndVisibleObjectLacksKey(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('results without identity are never tracked');

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $schema, [
			'id' => 1,
			'name' => 'Acme',
		], $session);
	}

	public function testAttachBindsFlatSourcesAndWalksRelations(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$schema = $this->projectionSchema($users, $registry->getCollection('companies'));
		$schema->addRelation(new RepresentationRelationSchema(
			'company',
			$users,
			'company',
			new RepresentationSchema($registry->getCollection('companies')),
		));
		$session = new Session(new RecordingCommandExecutor());
		$object = $this->flatUser(1, 'Acme');
		$object->company = null;

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		self::assertTrue($session->getRepresentations()->has($object));
		self::assertSame('users', $state->getRecordForField($state->getSchema()->getField('id'))->getCollection()->getName());
		self::assertSame('companies', $state->getRecordForField($state->getSchema()->getField('name'))->getCollection()->getName());
	}

	public function testAttachStoresFlatProjectionRecords(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertTrue($session->getRepresentations()->has($object));
		self::assertNotSame([], $session->getRecords()->getAll());
	}

	public function testSameCollectionFlatProjectionAttachesFieldsToDistinctRecords(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name', writable: true));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', writable: true, sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema(
			'manager.id',
			$users,
			'id',
			writable: false,
			sourcePath: ['manager'],
			role: RepresentationFieldRole::Implicit,
		));

		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Root';
		$object->managerName = 'Boss';

		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'name' => 'Root',
			'managerName' => 'Boss',
			'manager.id' => 9,
		], $session);

		$rootRecord = $session->getRecords()->getByKey($users->getKey(1));
		$managerRecord = $session->getRecords()->getByKey($users->getKey(9));

		self::assertInstanceOf(RecordState::class, $rootRecord);
		self::assertInstanceOf(RecordState::class, $managerRecord);
		self::assertNotSame($rootRecord->getStateHash(), $managerRecord->getStateHash());

		$nameField = $state->getSchema()->getField('name');
		$managerField = $state->getSchema()->getField('managerName');

		self::assertSame('users', $state->getRecordForField($nameField)->getCollection()->getName());
		self::assertSame('users', $state->getRecordForField($managerField)->getCollection()->getName());
		self::assertSame($rootRecord->getStateHash(), $state->getRecordForField($nameField)->getStateHash());
		self::assertSame($managerRecord->getStateHash(), $state->getRecordForField($managerField)->getStateHash());
	}

	public function testGroupsFieldSchemasBySourcePathNotTerminalCollection(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', writable: true, sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema(
			'manager.id',
			$users,
			'id',
			writable: false,
			sourcePath: ['manager'],
			role: RepresentationFieldRole::Implicit,
		));

		$object = new stdClass();
		$object->id = 1;
		$object->managerName = 'Boss';

		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection($object, $schema, [
			'id' => 1,
			'managerName' => 'Boss',
			'manager.id' => 9,
		], $session);

		$idField = $state->getSchema()->getField('id');
		$managerField = $state->getSchema()->getField('managerName');

		self::assertNotSame($state->getRecordForField($idField)->getStateHash(), $state->getRecordForField($managerField)->getStateHash());
		self::assertTrue($idField->isRootSource());
		self::assertSame(['manager'], $managerField->getSourcePath());
	}

	public function testSelfJoinSelectingTheSameRowBindsOneSharedRecordWithBothFieldSets(): void
	{
		$users = $this->makeSelfRelationRegistry()->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('managerId', $users, 'id', sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']));
		$row = ['id' => 2, 'managerId' => 2, 'managerName' => 'Ada'];
		$session = new Session(new RecordingCommandExecutor());

		$state = $this->adoptFlatProjection((object) $row, $schema, $row, $session);
		$root = $state->requireRootRecord();
		self::assertSame($root, $state->getRecordForField($schema->getField('managerName')));
		self::assertSame($root, $session->getRecords()->getByKey($users->getKey(2)));
		self::assertSame('Ada', $root->getValue('name'));
	}

	public function testIdentifyRejectsConflictingAliasesOfTheSameRowBeforeRegisteringIt(): void
	{
		$users = $this->makeSelfRelationRegistry()->getCollection('users');
		$schema = $this->selfJoinSchema($users);
		$object = (object) ['id' => 1, 'name' => 'Ada', 'managerId' => 1, 'managerName' => 'Bob'];
		$session = new Session(new RecordingCommandExecutor());

		try {
			$session->identify($users, ['id' => 1], $object, $schema);
			self::fail('Expected conflicting names for the same row to be rejected.');
		} catch (StateException $exception) {
			self::assertStringContainsString("disagree on field 'name'", $exception->getMessage());
		}
		self::assertSame([], $session->getRecords()->getAll());
		self::assertFalse($session->getRepresentations()->has($object));

		$object->managerName = 'Ada';
		$session->identify($users, ['id' => 1], $object, $schema);
		self::assertFalse($session->sync($object)->hasChanges());
		self::assertSame('Ada', $session->getRecords()->getByKey($users->getKey(1))->getValue('name'));
	}

	public function testIdentifyCanRetryAfterAConflictingChildWithoutLeavingAttachments(): void
	{
		$users = $this->makeSelfRelationRegistry()->getCollection('users');
		$childSchema = $this->selfJoinSchema($users);
		$parentSchema = new RepresentationSchema($users);
		$parentSchema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$parentSchema->addRelation(new RepresentationRelationSchema('manager', $users, 'manager', $childSchema));
		$child = (object) ['id' => 1, 'name' => 'Ada', 'managerId' => 1, 'managerName' => 'Bob'];
		$parent = (object) ['id' => 2, 'manager' => $child];
		$session = new Session(new RecordingCommandExecutor());

		try {
			$session->identify($users, ['id' => 2], $parent, $parentSchema);
			self::fail('Expected conflicting child aliases to be rejected.');
		} catch (StateException $exception) {
			self::assertStringContainsString("disagree on field 'name'", $exception->getMessage());
		}

		self::assertFalse($session->getRepresentations()->has($parent));
		self::assertFalse($session->getRepresentations()->has($child));
		self::assertSame([], $session->getRecords()->getAll());

		$child->managerName = 'Ada';
		$session->identify($users, ['id' => 2], $parent, $parentSchema);

		self::assertTrue($session->getRepresentations()->has($parent));
		$state = $session->getRepresentations()->get($child);
		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame($state->requireRootRecord(), $state->getRecordForField($childSchema->getField('managerName')));
		self::assertFalse($session->sync($child)->hasChanges());
	}

	public function testTrackCanRetryAfterALaterRowHasConflictingAliases(): void
	{
		$users = $this->makeSelfRelationRegistry()->getCollection('users');
		$schema = $this->selfJoinSchema($users);
		$managerSchema = new RepresentationSchema($users);
		$managerSchema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addRelation(new RepresentationRelationSchema('manager', $users, 'manager', $managerSchema));
		$query = new SelectQuery($users);
		$plan = new QueryRepresentationPlan($schema);
		$rows = [
			['id' => 1, 'name' => 'Ada', 'managerId' => 1, 'managerName' => 'Ada', 'manager' => null],
			['id' => 2, 'name' => 'Grace', 'managerId' => 2, 'managerName' => 'Bob', 'manager' => null],
		];
		$first = (object) $rows[0];
		$second = (object) $rows[1];
		$session = new Session(new RecordingCommandExecutor());

		try {
			$session->track($query, $plan, $rows, [$first, $second]);
			self::fail('Expected conflicting aliases in the second row to be rejected.');
		} catch (StateException $exception) {
			self::assertStringContainsString("disagree on field 'name'", $exception->getMessage());
		}

		self::assertFalse($session->getRepresentations()->has($first));
		self::assertFalse($session->getRepresentations()->has($second));
		self::assertSame([], $session->getRecords()->getAll());
		self::assertSame([], $session->getRelations()->getAll());

		$rows[1]['managerName'] = $second->managerName = 'Grace';
		$session->track($query, $plan, $rows, [$first, $second]);

		self::assertTrue($session->getRepresentations()->has($first));
		self::assertTrue($session->getRepresentations()->has($second));
		self::assertCount(2, $session->getRecords()->getAll());
		self::assertCount(2, $session->getRelations()->getAll());
		self::assertFalse($session->sync()->hasChanges());
	}

	public function testReusesExistingTrackedRecordState(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$existing = RecordState::clean($companies->getKey(5), ['id' => 5, 'name' => 'Existing']);
		$records = new RecordStateStore();
		$records->add($existing);
		$session = new Session(new RecordingCommandExecutor(), context: new SessionContext($records));

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $schema, [
			'id' => 1,
			'name' => 'Acme',
			'company.id' => 5,
		], $session);

		self::assertSame($existing, $session->getRecords()->getByKey($companies->getKey(5)));
	}

	/** @param array<string, mixed> $sourceRow */
	private function adoptFlatProjection(
		object $object,
		RepresentationSchema $schema,
		array $sourceRow,
		Session $session,
	): RepresentationState {
		return (new QueryResultHydrator($session->getContext()))->hydrate(
			$object,
			$schema,
			$sourceRow,
		);
	}

	private function flatUser(int $id, string $name): stdClass
	{
		$user = new stdClass();
		$user->id = $id;
		$user->name = $name;

		return $user;
	}

	private function projectionSchema(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationSchema {
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('name', $companies, 'name', writable: true, sourcePath: ['company']));
		$schema->addField(new RepresentationFieldSchema(
			'company.id',
			$companies,
			'id',
			writable: false,
			sourcePath: ['company'],
			role: RepresentationFieldRole::Implicit,
		));

		return $schema;
	}

	private function makeSelfRelationRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('manager_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('manager', 'users')
			->innerKey('manager_id')
			->outerKey('id');

		return $registry;
	}

	private function selfJoinSchema(CollectionInterface $users): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('managerId', $users, 'id', sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']));

		return $schema;
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('company_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('company', 'companies')
			->innerKey('company_id')
			->outerKey('id');

		return $registry;
	}
}
