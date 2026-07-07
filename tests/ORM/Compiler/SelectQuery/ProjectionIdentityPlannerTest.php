<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityPlanner;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class ProjectionIdentityPlannerTest extends TestCase
{
	private ProjectionIdentityPlanner $planner;

	protected function setUp(): void
	{
		$this->planner = new ProjectionIdentityPlanner();
	}

	public function testRootPrimaryKeyAlreadyPublicDoesNotAddHiddenSelection(): void
	{
		$users = $this->registry()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name'));

		$identities = $this->planner->plan($query, $binding);

		self::assertNull($identities->get([], 'id'));
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testRootMissingPrimaryKeyAddsHiddenInternalSelection(): void
	{
		$users = $this->registry()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name'));

		$identities = $this->planner->plan($query, $binding);

		$internal = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internal);
		self::assertNotNull($identities->get([], 'id'));
		self::assertSame($internal[0]->getSelectionKey(), $identities->get([], 'id'));
	}

	public function testRelationSourcedFieldWithSameTerminalCollectionAddsIndependentSelection(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name'));
		$binding->addField(new RepresentationFieldBinding('managerName', $users, 'name', sourcePath: ['manager']));

		$identities = $this->planner->plan($query, $binding);

		$internal = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internal);
		self::assertNull($identities->get([], 'id'));
		self::assertNotNull($identities->get(['manager'], 'id'));
		self::assertSame($internal[0]->getSelectionKey(), $identities->get(['manager'], 'id'));
	}

	public function testMultipleFieldsFromSameSourceAddIdentityOnce(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('managerName', $users, 'name', sourcePath: ['manager']));
		$binding->addField(new RepresentationFieldBinding('managerRef', $users, 'manager_id', sourcePath: ['manager']));

		$identities = $this->planner->plan($query, $binding);

		self::assertCount(1, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
		self::assertNotNull($identities->get(['manager'], 'id'));
	}

	public function testPublicRelationSourcedPrimaryKeyIsNotDuplicated(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('managerId', $users, 'id', sourcePath: ['manager']));
		$binding->addField(new RepresentationFieldBinding('managerName', $users, 'name', sourcePath: ['manager']));

		$identities = $this->planner->plan($query, $binding);

		self::assertNull($identities->get([], 'id'));
		self::assertNull($identities->get(['manager'], 'id'));
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testNestedSourcePathResolvesThroughRelationTraversal(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$binding->addField(new RepresentationFieldBinding('grandName', $users, 'name', sourcePath: ['manager', 'manager']));

		$identities = $this->planner->plan($query, $binding);

		self::assertCount(1, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
		self::assertNotNull($identities->get(['manager', 'manager'], 'id'));
	}

	private function registry(): Registry
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		return $registry;
	}

	private function registryWithManager(): Registry
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('manager_id', 'int')->end();

		$users = $registry->getCollection('users');
		self::assertInstanceOf(CollectionInterface::class, $users);
		$users->belongsTo('manager', 'users')->innerKey('manager_id')->outerKey('id');

		return $registry;
	}
}
