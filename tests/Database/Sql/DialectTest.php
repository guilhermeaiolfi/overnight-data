<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Sql;

use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\Sql\Dialect\AbstractDialect;
use ON\Data\Database\Sql\Dialect\DialectFactory;
use ON\Data\Database\Sql\Dialect\MySqlDialect;
use ON\Data\Database\Sql\Dialect\PostgresDialect;
use ON\Data\Database\Sql\Dialect\SqliteDialect;
use ON\Data\Database\Sql\Dialect\SqlServerDialect;
use ON\Data\Database\Sql\SqlQueryCompiler;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Query\query;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DialectTest extends TestCase
{
	#[DataProvider('mariaDbVersions')]
	public function testMariaDbVersionDeterminesInsertReturning(string $version, bool $expected): void
	{
		$pdo = $this->createStub(PDO::class);
		$pdo->method('getAttribute')->willReturnMap([
			[PDO::ATTR_DRIVER_NAME, 'mysql'],
			[PDO::ATTR_SERVER_VERSION, $version],
		]);
		$connection = new PdoConnection($pdo);

		self::assertSame(DatabaseFamily::MariaDB, $connection->family());
		self::assertSame($expected, DialectFactory::forConnection($connection)->supportsInsertReturning());
	}

	public static function mariaDbVersions(): iterable
	{
		yield 'older version' => ['5.5.5-10.4.33-MariaDB', false];
		yield 'first supporting version' => ['5.5.5-10.5.0-MariaDB', true];
		yield 'distribution version string' => ['10.11.6-MariaDB-0+deb12u1', true];
	}

	public function testSqlServerUsesTopForZeroLimitAndAllowsOrderingInDerivedQueries(): void
	{
		$dialect = new SqlServerDialect();
		$select = $dialect->compileSelectPrefix(0);
		self::assertSame('SELECT TOP (?)', $select->sql());
		self::assertSame([0], $select->parameters());
		self::assertSame('', $dialect->compileLimitOffset(0, 5, true, nested: true)->sql());

		$pagination = $dialect->compileLimitOffset(null, null, true, nested: true);
		self::assertSame('OFFSET ? ROWS', $pagination->sql());
		self::assertSame([0], $pagination->parameters());
	}

	public function testSqlServerBindsNestedParametersInPlaceholderOrder(): void
	{
		$notes = (new Registry())->collection('notes')->table('app.notes')->field('id', 'int')->end();
		$inner = query($notes);
		$inner->select($inner->id)->where($inner->id->gt(10))->orderBy($inner->id->asc());
		$wrapped = $inner->as('wrapped');
		$query = query($wrapped)
			->select(x()->rawSql('?', ['note'])->as('label'))
			->where($wrapped->field('id')->lt(20))
			->limit(0);
		$compiler = new SqlQueryCompiler(
			new SqlServerDialect(),
			$this->createStub(DatabasePlatformInterface::class),
			ConversionGateway::createDefault(),
			'prefix_',
		);

		$statement = $compiler->compile($query);

		self::assertSame(
			'SELECT TOP (?) ? AS [label] FROM (SELECT [q1].[id] AS [id] FROM [app].[prefix_notes] AS [q1]'
				. ' WHERE [q1].[id] > ? ORDER BY [q1].[id] ASC OFFSET ? ROWS) AS [wrapped] WHERE [wrapped].[id] < ?',
			$statement->sql,
		);
		self::assertSame([0, 'note', 10, 0, 20], $statement->params);
	}

	#[DataProvider('dialects')]
	public function testDml(
		AbstractDialect $dialect,
		string $alias,
		string $table,
		string $emptyInsert,
		string $insert,
		string $concat,
		string $offsetOnly,
		string $pagination,
		array $params,
	): void {
		self::assertSame($alias, $dialect->quoteIdentifier('a."b`c]'));
		self::assertSame($table, $dialect->quoteTable('app.users', 'on_'));
		$id = $dialect->quoteIdentifier('id');
		$name = $dialect->quoteIdentifier('name');
		$returning = $dialect->supportsInsertReturning() ? [$id] : [];
		self::assertSame($emptyInsert, $dialect->compileInsert($table, [], [], $returning));
		self::assertSame($insert, $dialect->compileInsert($table, [$name], ['?'], $returning));
		self::assertSame($concat, $dialect->compileConcat(['?', '?']));

		$offset = $dialect->compileLimitOffset(null, 8, false);
		self::assertSame($offsetOnly, $offset->sql());
		self::assertSame([8], $offset->parameters());

		$page = $dialect->compileLimitOffset(5, 8, true);
		self::assertSame($pagination, $page->sql());
		self::assertSame($params, $page->parameters());
	}

	public static function dialects(): iterable
	{
		yield 'SQLite' => [
			new SqliteDialect(),
			'"a.""b`c]"', '"app"."on_users"',
			'INSERT INTO "app"."on_users" DEFAULT VALUES RETURNING "id"',
			'INSERT INTO "app"."on_users" ("name") VALUES (?) RETURNING "id"',
			'(? || ?)', 'LIMIT -1 OFFSET ?', 'LIMIT ? OFFSET ?', [5, 8],
		];
		yield 'PostgreSQL' => [
			new PostgresDialect(),
			'"a.""b`c]"', '"app"."on_users"',
			'INSERT INTO "app"."on_users" DEFAULT VALUES RETURNING "id"',
			'INSERT INTO "app"."on_users" ("name") VALUES (?) RETURNING "id"',
			'(? || ?)', 'OFFSET ?', 'LIMIT ? OFFSET ?', [5, 8],
		];
		yield 'MySQL' => [
			new MySqlDialect(),
			'`a."b``c]`', '`app`.`on_users`',
			'INSERT INTO `app`.`on_users` () VALUES ()',
			'INSERT INTO `app`.`on_users` (`name`) VALUES (?)',
			'CONCAT(?, ?)', 'LIMIT 18446744073709551615 OFFSET ?', 'LIMIT ? OFFSET ?', [5, 8],
		];
		yield 'MariaDB' => [
			new MySqlDialect(DatabaseFamily::MariaDB, true),
			'`a."b``c]`', '`app`.`on_users`',
			'INSERT INTO `app`.`on_users` () VALUES () RETURNING `id`',
			'INSERT INTO `app`.`on_users` (`name`) VALUES (?) RETURNING `id`',
			'CONCAT(?, ?)', 'LIMIT 18446744073709551615 OFFSET ?', 'LIMIT ? OFFSET ?', [5, 8],
		];
		yield 'SQL Server' => [
			new SqlServerDialect(),
			'[a."b`c]]]', '[app].[on_users]',
			'INSERT INTO [app].[on_users] OUTPUT INSERTED.[id] DEFAULT VALUES',
			'INSERT INTO [app].[on_users] ([name]) OUTPUT INSERTED.[id] VALUES (?)',
			'CONCAT(?, ?)', 'ORDER BY (SELECT NULL) OFFSET ? ROWS', 'OFFSET ? ROWS FETCH NEXT ? ROWS ONLY', [8, 5],
		];
	}
}
