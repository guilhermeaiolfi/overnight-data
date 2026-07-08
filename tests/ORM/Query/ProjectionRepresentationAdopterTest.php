<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\ProjectionSourceBuilder;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompilation;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityColumns;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Query\ProjectionRepresentationAdopter;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ProjectionRepresentationAdopterTest extends TestCase
{
	public function testAdoptsFlatObjectWithFieldsFromTwoCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adopter()->adopt($object, $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

		self::assertTrue($context->getRepresentations()->has($object));
		self::assertSame($state, $context->getRepresentations()->get($object));
		self::assertTrue($state->getBinding()->hasField('id'));
		self::assertTrue($state->getBinding()->hasField('name'));
	}

	public function testCreatesOneRecordStatePerCollection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		self::assertNotSame($userRecord, $companyRecord);
	}

	public function testRepresentationStateItemsAttachFieldsToBothCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

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
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$state = $this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));

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
		$binding = $this->projectionBinding($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adopter()->adopt($object, $this->compilation($binding, $identityColumns), [
			'company_id' => 5,
		], $context);

		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));
		self::assertInstanceOf(RecordState::class, $companyRecord);
	}

	public function testVisibleIdentityValuesCanComeFromObjectProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adopter()->adopt($object, $this->compilation($binding, $identityColumns), [
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		self::assertInstanceOf(RecordState::class, $userRecord);
	}

	public function testThrowsWhenMapEntryIsMissingAndVisibleObjectLacksKey(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("primary key field 'id' is missing or incomplete");

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, new ProjectionIdentityColumns()), [
			'id' => 1,
			'name' => 'Acme',
		], $context);
	}

	public function testThrowsWhenMapPointsToMissingSourceRowKey(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'missing_key');

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("internal result key 'missing_key' for primary key field 'id' is missing from the source row");

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
		], $context);
	}

	public function testThrowsWhenBindingContainsRelationBindings(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$binding = $this->projectionBinding($users, $registry->getCollection('companies'));
		$binding->addRelation(new RepresentationRelationBinding(
			'company',
			$users,
			'company',
			new RepresentationBinding($registry->getCollection('companies')),
		));
		$context = new SessionContext();
		$identityColumns = $this->companyIdProjectionIdentities($registry->getCollection('companies'), 'company_id');

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('binding contains relation bindings');

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);
	}

	public function testSameCollectionFlatProjectionAttachesFieldsToDistinctRecords(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name', writable: true));
		$binding->addField(new RepresentationFieldBinding('managerName', $users, 'name', writable: true, sourcePath: ['manager']));

		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Root';
		$object->managerName = 'Boss';

		$context = new SessionContext();
		$identities = new ProjectionIdentityColumns();
		$identities->add(['manager'], 'id', 'manager_id');

		$state = $this->adopter()->adopt($object, $this->compilation($binding, $identities), [
			'id' => 1,
			'name' => 'Root',
			'managerName' => 'Boss',
			'manager_id' => 9,
		], $context);

		$rootRecord = $context->getRecords()->getByKey($users->getKey(1));
		$managerRecord = $context->getRecords()->getByKey($users->getKey(9));

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

	public function testGroupsFieldBindingsBySourcePathNotTerminalCollection(): void
	{
		$registry = $this->makeSelfRelationRegistry();
		$users = $registry->getCollection('users');
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('managerName', $users, 'name', writable: true, sourcePath: ['manager']));

		$object = new stdClass();
		$object->id = 1;
		$object->managerName = 'Boss';

		$context = new SessionContext();
		$identities = new ProjectionIdentityColumns();
		$identities->add(['manager'], 'id', 'manager_id');

		$state = $this->adopter()->adopt($object, $this->compilation($binding, $identities), [
			'id' => 1,
			'managerName' => 'Boss',
			'manager_id' => 9,
		], $context);

		$idItem = $state->getFieldItem('id');
		$managerItem = $state->getFieldItem('managerName');

		self::assertNotSame($idItem->getRecord()->getStateHash(), $managerItem->getRecord()->getStateHash());
		self::assertTrue($idItem->getBinding()->isRootSource());
		self::assertSame(['manager'], $managerItem->getBinding()->getSourcePath());
	}

	public function testReusesExistingTrackedRecordState(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$binding = $this->projectionBinding($users, $companies);
		$existing = RecordState::clean($companies->getKey(5), ['id' => 5, 'name' => 'Existing']);
		$records = new RecordStateStore();
		$records->add($existing);
		$context = new SessionContext($records);
		$identityColumns = $this->companyIdProjectionIdentities($companies, 'company_id');

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $this->compilation($binding, $identityColumns), [
			'id' => 1,
			'name' => 'Acme',
			'company_id' => 5,
		], $context);

		self::assertSame($existing, $context->getRecords()->getByKey($companies->getKey(5)));
	}

	private function adopter(): ProjectionRepresentationAdopter
	{
		return new ProjectionRepresentationAdopter();
	}

	private function compilation(
		RepresentationBinding $binding,
		ProjectionIdentityColumns $identityColumns,
	): ProjectionCompilation {
		return new ProjectionCompilation(
			$binding,
			(new ProjectionSourceBuilder())->build($binding),
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

	private function companyIdProjectionIdentities(CollectionInterface $companies, string $resultKey): ProjectionIdentityColumns
	{
		$map = new ProjectionIdentityColumns();
		$map->add(['company'], 'id', $resultKey);

		return $map;
	}

	private function projectionBinding(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationBinding {
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('name', $companies, 'name', writable: true, sourcePath: ['company']));

		return $binding;
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
