<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use ON\Data\Database\Cycle\ConnectionConfig;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\WireRepresentation;
use function ON\Data\Query\x;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class QuickstartSmokeTest extends TestCase
{
	public function testDocumentedQuickstartFlow(): void
	{
		self::assertInstanceOf(DataRuntime::class, (new CycleRuntimeFactory())->connect(ConnectionConfig::dsn('sqlite', 'sqlite::memory:')));

		$registry = new Registry();

		$registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('active', 'bool')->end()
			->hasMany('posts', 'posts')
				->innerKey('id')
				->outerKey('user_id')
				->end();

		$registry->collection('posts')
			->table('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->column('user_id')->end()
			->field('title', 'string')->end()
			->field('published', 'bool')->end();

		$users = $registry->getCollection('users');

		$normalized = map([
			'id' => '10',
			'name' => 'Ada',
			'active' => '1',
		])
			->from(WireRepresentation::class)
			->args($users)
			->to([]);

		self::assertSame(['id' => 10, 'name' => 'Ada', 'active' => true], $normalized);

		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, published INTEGER)');
		$harness->exec('INSERT INTO users (id, name, active) VALUES (1, \'Ada\', 1)');

		$query = $harness->database->query($users);

		$rows = $query
			->select($query->id, $query->name)
			->where(x()->eq($query->active, true))
			->orderBy($query->id->asc())
			->fetchAll();

		self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);

		$userQuery = $harness->database->query($users);

		$objects = $userQuery
			->select($userQuery->id, $userQuery->name)
			->to(UserRow::class)
			->fetchAll();

		self::assertCount(1, $objects);
		self::assertInstanceOf(UserRow::class, $objects[0]);
		self::assertSame(1, $objects[0]->id);
		self::assertSame('Ada', $objects[0]->name);
	}
}

final class UserRow
{
	public int $id;

	public string $name;
}
