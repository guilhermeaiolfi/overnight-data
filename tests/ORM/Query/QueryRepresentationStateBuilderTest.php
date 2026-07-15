<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityColumns;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\State\Query\QueryRepresentationStateBuilder;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\SessionContext;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class QueryRepresentationStateBuilderTest extends TestCase
{
	public function testBuildsFlatObjectWithFieldsFromTwoCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$session = new Session(new RecordingCommandExecutor());
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adoptFlatProjection($object, $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
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
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $session->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		self::assertNotSame($userRecord, $companyRecord);
	}

	public function testRepresentationStateItemsAttachFieldsToBothCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $session);

		$idItem = $state->getFieldItem('id');
		$nameItem = $state->getFieldItem('name');

		self::assertSame('users', $idItem->getRecord()->getCollection()->getName());
		self::assertSame('companies', $nameItem->getRecord()->getCollection()->getName());
		self::assertNotSame($idItem->getRecord()->getStateHash(), $nameItem->getRecord()->getStateHash());
	}

	public function testBaselineRevisionsIncludeBothRecordHashes(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $session->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		$idItem = $state->getFieldItem('id');
		$nameItem = $state->getFieldItem('name');
		self::assertSame($userRecord->getStateHash(), $idItem->getRecord()->getStateHash());
		self::assertSame($companyRecord->getStateHash(), $nameItem->getRecord()->getStateHash());
		self::assertSame($userRecord->getRevision(), $idItem->getBaselineRevision());
		self::assertSame($companyRecord->getRevision(), $nameItem->getBaselineRevision());
	}

	public function testHiddenIdentityValuesAreReadThroughProjectionIdentityColumns(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$session = new Session(new RecordingCommandExecutor());
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adoptFlatProjection($object, $this->compilation($schema, $identityColumns), [
			'company_id' => 5,
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
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adoptFlatProjection($object, $this->compilation($schema, $identityColumns), [
			'name' => 'Acme',
			'company_id' => 5,
		], $session);

		$userRecord = $session->getRecords()->getByKey($users->getKey(1));
		self::assertInstanceOf(RecordState::class, $userRecord);
	}

	public function testThrowsWhenMapEntryIsMissingAndVisibleObjectLacksKey(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("primary key field 'id' is missing or incomplete");

		$this->builder()->build($this->flatUser(1, 'Acme'), $this->compilation($schema, new QueryRepresentationIdentityColumns()), [
			'id' => 1,
			'name' => 'Acme',
		], $session->getRecords());
	}

	public function testThrowsWhenMapPointsToMissingSourceRowKey(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$session = new Session(new RecordingCommandExecutor());
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'missing_key');

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("internal result key 'missing_key' for primary key field 'id' is missing from the source row");

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
		], $session);
	}

	public function testThrowsWhenSchemaContainsRelationSchemas(): void
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
		$identityColumns = $this->companyIdProjectionIdentities($registry->getCollection('companies'), 'company_id');

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('schema contains relation schemas');

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $session);
	}

	public function testSameCollectionFlatProjectionAttachesFieldsToDistinctRecords(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name', writable: true));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', writable: true, sourcePath: ['manager']));

		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Root';
		$object->managerName = 'Boss';

		$session = new Session(new RecordingCommandExecutor());
		$identities = new QueryRepresentationIdentityColumns();
		$identities->add(['manager'], 'id', 'manager_id');

		$state = $this->adoptFlatProjection($object, $this->compilation($schema, $identities), [
			'id' => 1,
			'name' => 'Root',
			'managerName' => 'Boss',
			'manager_id' => 9,
		], $session);

		$rootRecord = $session->getRecords()->getByKey($users->getKey(1));
		$managerRecord = $session->getRecords()->getByKey($users->getKey(9));

		self::assertInstanceOf(RecordState::class, $rootRecord);
		self::assertInstanceOf(RecordState::class, $managerRecord);
		self::assertNotSame($rootRecord->getStateHash(), $managerRecord->getStateHash());

		$nameItem = $state->getFieldItem('name');
		$managerItem = $state->getFieldItem('managerName');

		self::assertSame('users', $nameItem->getRecord()->getCollection()->getName());
		self::assertSame('users', $managerItem->getRecord()->getCollection()->getName());
		self::assertSame($rootRecord->getStateHash(), $nameItem->getRecord()->getStateHash());
		self::assertSame($managerRecord->getStateHash(), $managerItem->getRecord()->getStateHash());
	}

	public function testGroupsFieldSchemasBySourcePathNotTerminalCollection(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', writable: true, sourcePath: ['manager']));

		$object = new stdClass();
		$object->id = 1;
		$object->managerName = 'Boss';

		$session = new Session(new RecordingCommandExecutor());
		$identities = new QueryRepresentationIdentityColumns();
		$identities->add(['manager'], 'id', 'manager_id');

		$state = $this->adoptFlatProjection($object, $this->compilation($schema, $identities), [
			'id' => 1,
			'managerName' => 'Boss',
			'manager_id' => 9,
		], $session);

		$idItem = $state->getFieldItem('id');
		$managerItem = $state->getFieldItem('managerName');

		self::assertNotSame($idItem->getRecord()->getStateHash(), $managerItem->getRecord()->getStateHash());
		self::assertTrue($idItem->getSchema()->isRootSource());
		self::assertSame(['manager'], $managerItem->getSchema()->getSourcePath());
	}

	public function testBuildReturnsRepresentationStateWithoutStoring(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$records = new RecordStateStore();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->builder()->build($object, $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $records);

		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame([], $records->getAll());
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
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adoptFlatProjection($this->flatUser(1, 'Acme'), $this->compilation($schema, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $session);

		self::assertSame($existing, $session->getRecords()->getByKey($companies->getKey(5)));
	}

	private function builder(): QueryRepresentationStateBuilder
	{
		return new QueryRepresentationStateBuilder();
	}

	private function adoptFlatProjection(
		object $object,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
		Session $session,
	): RepresentationState {
		$state = $this->builder()->build($object, $compilation, $sourceRow, $session->getRecords());
		$session->adopt($object, $state);

		return $state;
	}

	private function compilation(
		RepresentationSchema $schema,
		QueryRepresentationIdentityColumns $identityColumns,
	): QueryRepresentationPlan {
		return new QueryRepresentationPlan(
			$schema,
			RepresentationSource::fromRepresentationSchema($schema),
			$identityColumns,
		);
	}

	private function flatUser(int $id, string $name): stdClass
	{
		$user = new stdClass();
		$user->id = $id;
		$user->name = $name;

		return $user;
	}

	private function companyIdProjectionIdentities(CollectionInterface $companies, string $resultKey): QueryRepresentationIdentityColumns
	{
		$map = new QueryRepresentationIdentityColumns();
		$map->add(['company'], 'id', $resultKey);

		return $map;
	}

	private function projectionSchema(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationSchema {
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('name', $companies, 'name', writable: true, sourcePath: ['company']));

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
