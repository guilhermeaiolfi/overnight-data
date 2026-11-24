<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Sql;

use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\SqlRuntimeFactory;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Persistence\ConvertingCommandExecutor;
use ON\Data\ORM\Persistence\InsertCommand;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomFieldType;

#[RequiresPhpExtension('pdo_sqlite')]
final class SqlRuntimeFactoryTest extends TestCase
{
	public function testConnectWrapsWritesWithConversion(): void
	{
		$runtime = (new SqlRuntimeFactory())->connect(new ConnectionConfig('sqlite::memory:'));

		self::assertInstanceOf(ConvertingCommandExecutor::class, $runtime->getCommandExecutor());
	}

	public function testCreateUsesTheSuppliedPdo(): void
	{
		$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_CASE => PDO::CASE_UPPER]);
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$runtime = (new SqlRuntimeFactory())->create($pdo);
		$users = (new Registry())->collection('users')
			->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class)->end()
			->field('name', 'string')->end();

		$result = $runtime->getCommandExecutor()->execute(new InsertCommand($users, ['name' => 'Ada']));
		self::assertSame(['id' => 1], $result->getGeneratedValues());
		self::assertSame(['id' => 1, 'name' => 'Ada'], $runtime->query($users)->fetchOne());
	}

	public function testCustomGatewayConvertsWritesQueryLiteralsAndResults(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		$connection->execute('CREATE TABLE users (name TEXT)');
		$users = (new Registry())->collection('users')->field('name', 'custom')->end();
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(CustomFieldType::class);
		$runtime = (new SqlRuntimeFactory())->create($connection, $gateway);

		$runtime->getCommandExecutor()->execute(new InsertCommand($users, ['name' => 'Ada']));
		$query = $runtime->query($users);

		self::assertSame(['name' => 'ada'], $connection->fetchOne('SELECT name FROM users'));
		self::assertSame(['name' => 'ADA'], $query->where($query->name->eq('Ada'))->fetchOne());
	}

	public function testCustomConnectionCanDisableReturning(): void
	{
		$connection = $this->createMock(ConnectionInterface::class);
		$connection->method('family')->willReturn(DatabaseFamily::SqlServer);
		$connection->expects(self::once())->method('execute')
			->with('INSERT INTO [users] ([id]) VALUES (?)', [7])
			->willReturn(1);
		$connection->expects(self::once())->method('fetchOne')
			->with('SELECT [name] FROM [users] WHERE [id] = ? ORDER BY (SELECT NULL) OFFSET ? ROWS FETCH NEXT ? ROWS ONLY', [7, 0, 1])
			->willReturn(['name' => 'Ada']);
		$runtime = (new SqlRuntimeFactory(insertReturning: false))->create($connection);
		$users = (new Registry())->collection('users')->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$result = $runtime->getCommandExecutor()->execute(new InsertCommand($users, ['id' => 7]));

		self::assertSame(['name' => 'Ada'], $result->getGeneratedValues());
	}

	public function testReadAndWriteUsePrefixOnQualifiedTable(): void
	{
		$connection = PdoConnection::connect(new ConnectionConfig('sqlite::memory:', tablePrefix: 'app_'));
		$connection->execute("CREATE TABLE app_users (id INTEGER PRIMARY KEY, name TEXT DEFAULT 'Ada')");
		$runtime = (new SqlRuntimeFactory())->create($connection);
		$users = (new Registry())->collection('users')
			->table('main.users')->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class)->end()
			->field('name', 'string')->end();

		$result = $runtime->getCommandExecutor()->execute(new InsertCommand($users, []));
		self::assertSame(['id' => 1, 'name' => 'Ada'], $result->getGeneratedValues());
		self::assertSame(['id' => 1, 'name' => 'Ada'], $runtime->query($users)->fetchOne());
	}
}
