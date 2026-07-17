<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityPlanner;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class ProjectionIdentityPlannerTest extends TestCase
{
	private QueryRepresentationIdentityPlanner $planner;

	protected function setUp(): void
	{
		$this->planner = new QueryRepresentationIdentityPlanner();
	}

	public function testRootPrimaryKeyAlreadyPublicDoesNotAddHiddenSelection(): void
	{
		$users = $this->registry()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('id', $users, 'id', writable: false),
				new RepresentationFieldSchema('name', $users, 'name'),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		self::assertNull($identities->getResultKey([], 'id'));
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testRootMissingPrimaryKeyAddsHiddenInternalSelection(): void
	{
		$users = $this->registry()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('name', $users, 'name'),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		$internal = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internal);
		self::assertNotNull($identities->getResultKey([], 'id'));
		self::assertSame($internal[0]->getSelectionKey(), $identities->getResultKey([], 'id'));
	}

	public function testRelationSourcedFieldWithSameTerminalCollectionAddsIndependentSelection(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('id', $users, 'id', writable: false),
				new RepresentationFieldSchema('name', $users, 'name'),
			),
			$this->source(
				$users,
				['manager'],
				new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		$internal = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internal);
		self::assertNull($identities->getResultKey([], 'id'));
		self::assertNotNull($identities->getResultKey(['manager'], 'id'));
		self::assertSame($internal[0]->getSelectionKey(), $identities->getResultKey(['manager'], 'id'));
	}

	public function testMultipleFieldsFromSameSourceAddIdentityOnce(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('id', $users, 'id', writable: false),
			),
			$this->source(
				$users,
				['manager'],
				new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']),
				new RepresentationFieldSchema('managerRef', $users, 'manager_id', sourcePath: ['manager']),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		self::assertCount(1, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
		self::assertNotNull($identities->getResultKey(['manager'], 'id'));
	}

	public function testPublicRelationSourcedPrimaryKeyIsNotDuplicated(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('id', $users, 'id', writable: false),
			),
			$this->source(
				$users,
				['manager'],
				new RepresentationFieldSchema('managerId', $users, 'id', sourcePath: ['manager']),
				new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		self::assertNull($identities->getResultKey([], 'id'));
		self::assertNull($identities->getResultKey(['manager'], 'id'));
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testNestedSourcePathResolvesThroughRelationTraversal(): void
	{
		$users = $this->registryWithManager()->getCollection('users');
		$query = new SelectQuery($users);
		$sources = [
			$this->source(
				$users,
				[],
				new RepresentationFieldSchema('id', $users, 'id', writable: false),
			),
			$this->source(
				$users,
				['manager', 'manager'],
				new RepresentationFieldSchema('grandName', $users, 'name', sourcePath: ['manager', 'manager']),
			),
		];

		$identities = $this->planner->plan($query, $sources);

		self::assertCount(1, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
		self::assertNotNull($identities->getResultKey(['manager', 'manager'], 'id'));
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

	/**
	 * @param list<string> $path
	 */
	private function source(
		CollectionInterface $collection,
		array $path,
		RepresentationFieldSchema ...$fields,
	): RepresentationSource {
		return new RepresentationSource($path, $collection, $fields);
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
