<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class WritableProjectionSmokeTest extends TestCase
{
	public function testWritableFlatProjectionUpdatesAliasedRelatedFieldInDatabase(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');
		$harness->exec("INSERT INTO companies (id, name) VALUES (5, 'Acme')");
		$harness->exec("INSERT INTO users (id, company_id, name) VALUES (1, 5, 'Ada')");

		$registry = new Registry();

		$registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('company_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('companies')
			->table('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('company', 'companies')
			->innerKey('company_id')
			->outerKey('id');

		$users = $registry->getCollection('users');
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->company->name->as('name'));

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame('Acme', $user->name);

		$user->name = 'Dell';
		$session->sync($user);
		$session->flush();

		$row = $harness->fetchRow('SELECT name FROM companies WHERE id = 5');
		self::assertSame(['name' => 'Dell'], $row);
	}

	public function testWritableSameCollectionFlatProjectionUpdatesTheCorrectRecord(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, manager_id INTEGER, name TEXT)');
		$harness->exec("INSERT INTO users (id, manager_id, name) VALUES (9, NULL, 'Boss')");
		$harness->exec("INSERT INTO users (id, manager_id, name) VALUES (1, 9, 'Ada')");

		$registry = new Registry();

		$registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('manager_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('manager', 'users')
			->innerKey('manager_id')
			->outerKey('id');

		$users = $registry->getCollection('users');
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name, $query->manager->name->as('managerName'));

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame('Ada', $user->name);
		self::assertSame('Boss', $user->managerName);

		$user->name = 'Ada Lovelace';
		$user->managerName = 'Big Boss';
		$session->sync($user);
		$session->flush();

		self::assertSame(['name' => 'Ada Lovelace'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertSame(['name' => 'Big Boss'], $harness->fetchRow('SELECT name FROM users WHERE id = 9'));
	}
}
