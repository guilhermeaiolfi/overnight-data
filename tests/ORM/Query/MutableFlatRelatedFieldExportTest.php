<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class MutableFlatRelatedFieldExportTest extends TestCase
{
	public function testMutableFlatRelatedFieldEditFlushesUpdateToRelatedCollection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($users, new FlatCompanyUserQueryExecutor());
		$query->select($query->id, $query->company->name->as('name'));

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Acme', $user->name);
		self::assertFalse(property_exists($user, 'company'));
		self::assertFalse(property_exists($user, '__od.company.id'));

		$user->name = 'Dell';
		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($companies, $command->getCollection());
		self::assertSame(['id' => 5], $command->getIdentity());
		self::assertSame(['name' => 'Dell'], $command->getChanges());
		self::assertNotSame($users, $command->getCollection());
	}

	public function testMutableFlatRelatedFieldEditDoesNotProduceUsersNameUpdateOrInsert(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($users, new FlatCompanyUserQueryExecutor());
		$query->select($query->id, $query->company->name->as('name'));

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->name = 'Dell';
		$session->sync($user);
		$session->flush();

		foreach ($executor->getCommands() as $command) {
			self::assertNotInstanceOf(InsertCommand::class, $command);

			if ($command instanceof UpdateCommand) {
				self::assertNotSame($users, $command->getCollection());

				if ($command->getCollection() === $users) {
					self::assertArrayNotHasKey('name', $command->getChanges(), 'users.name must not be updated.');
				}
			}
		}
	}

	public function testMissingHiddenCompanyIdThrowsDuringProjectionAdoption(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$session = new Session(new RecordingCommandExecutor());
		$query = new SelectQuery($users, new FlatCompanyUserQueryExecutorWithoutCompanyId());
		$query->select($query->id, $query->company->name->as('name'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("primary key field 'id' is missing or incomplete");

		$query->to(stdClass::class)->mutable($session)->fetchOne();
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

final class FlatCompanyUserQueryExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [$this->fetchOne($query) ?? []];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return [
			'id' => 1,
			'name' => 'Acme',
			'__od.company.id' => 5,
		];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}

final class FlatCompanyUserQueryExecutorWithoutCompanyId implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [$this->fetchOne($query) ?? []];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return [
			'id' => 1,
			'name' => 'Acme',
		];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}
