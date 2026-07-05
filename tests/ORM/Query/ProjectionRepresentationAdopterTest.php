<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Query\ProjectionRepresentationAdopter;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ProjectionRepresentationAdopterTest extends TestCase
{
	public function testAdoptsFlatObjectWithFieldsFromTwoCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$object = $this->flatUser(1, 'Acme');
		$context = new SessionContext();

		$state = $this->adopter()->adopt($object, $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
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
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		self::assertNotSame($userRecord, $companyRecord);
	}

	public function testAppliedBindingHasConcreteRecordFieldRefTargetsForBothCollections(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();

		$state = $this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);

		$idField = $state->getBinding()->getField('id')->getField();
		$nameField = $state->getBinding()->getField('name')->getField();

		self::assertTrue($idField->hasState());
		self::assertTrue($nameField->hasState());
		self::assertSame('users', $idField->getCollectionName());
		self::assertSame('companies', $nameField->getCollectionName());
		self::assertNotSame($idField->getRecordHash(), $nameField->getRecordHash());
	}

	public function testBaselineRevisionsIncludeBothRecordHashes(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();

		$state = $this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));

		self::assertInstanceOf(RecordState::class, $userRecord);
		self::assertInstanceOf(RecordState::class, $companyRecord);
		self::assertTrue($state->hasBaselineRevision($userRecord->getStateHash()));
		self::assertTrue($state->hasBaselineRevision($companyRecord->getStateHash()));
	}

	public function testHiddenIdentityValuesCanComeFromSourceRow(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$context = new SessionContext();

		$this->adopter()->adopt($object, $binding, $query, [
			'__od.company.id' => 5,
		], $context);

		$companyRecord = $context->getRecords()->getByKey($companies->getKey(5));
		self::assertInstanceOf(RecordState::class, $companyRecord);
	}

	public function testVisibleIdentityValuesCanComeFromObjectProperties(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$object = new stdClass();
		$object->id = 1;
		$object->name = 'Acme';
		$context = new SessionContext();

		$this->adopter()->adopt($object, $binding, $query, [
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);

		$userRecord = $context->getRecords()->getByKey($users->getKey(1));
		self::assertInstanceOf(RecordState::class, $userRecord);
	}

	public function testThrowsWhenKeyForReferencedCollectionIsMissing(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$context = new SessionContext();

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("primary key field 'id' is missing or incomplete");

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
		], $context);
	}

	public function testThrowsWhenBindingContainsRelationBindings(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $registry->getCollection('companies'));
		$binding->addRelation(new RepresentationRelationBinding(
			'company',
			RecordRelationRef::forCollection($users, 'company'),
			RepresentationRelationCardinality::ONE,
			new RepresentationBinding(),
		));
		$context = new SessionContext();

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('binding contains relation bindings');

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);
	}

	public function testReusesExistingTrackedRecordState(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id));
		$binding = $this->projectionBinding($users, $companies);
		$existing = RecordState::clean($companies->getKey(5), ['id' => 5, 'name' => 'Existing']);
		$records = new RecordStateStore();
		$records->add($existing);
		$context = new SessionContext($records);

		$this->adopter()->adopt($this->flatUser(1, 'Acme'), $binding, $query, [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		], $context);

		self::assertSame($existing, $context->getRecords()->getByKey($companies->getKey(5)));
	}

	private function adopter(): ProjectionRepresentationAdopter
	{
		return new ProjectionRepresentationAdopter();
	}

	private function flatUser(int $id, string $name): stdClass
	{
		$user = new stdClass();
		$user->id = $id;
		$user->name = $name;

		return $user;
	}

	private function projectionBinding(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationBinding {
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($users, 'id'), writable: false));
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($companies, 'name'), writable: true));
		$binding->addField(new RepresentationFieldBinding('__od.company.id', RecordFieldRef::template($companies, 'id'), writable: false));

		return $binding;
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
