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
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingOptions;
use ON\Data\ORM\History\FlushHistory;
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

	public function testRuntimeSharesCustomMappingBetweenQueryResultsAndSessionFlush(): void
	{
		$pdo = new PDO('sqlite::memory:');
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
		$pdo->exec("INSERT INTO users VALUES (1, 'Ada')");
		$users = (new Registry())->collection('users')->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeUserMapper::class);
		$runtime = (new SqlRuntimeFactory())->create($pdo, $gateway);

		$query = $runtime->query($users);
		$query->select($query->id, $query->name)->to(RuntimeMappedUser::class);
		$expected = new RuntimeMappedUser(1, 'ADA');
		self::assertEquals([$expected], $query->fetchAll());
		self::assertEquals($expected, $query->fetchOne(1));
		self::assertEquals([$expected], iterator_to_array($query->copy()->iterate(), false));
		$schema = $query->projection();
		self::assertEquals($expected, $runtime->query($schema)->to(RuntimeMappedUser::class)->fetchOne());

		$history = new FlushHistory();
		$session = $runtime->session($history);
		$created = new RuntimeMappedUser(2, 'GRACE');
		$session->create($created, $schema);
		$session->flush('Create user');

		self::assertSame($gateway, $runtime->getGateway());
		self::assertSame('Create user', $history->latest()?->label);
		self::assertSame(['id' => 2, 'name' => 'grace'], $runtime->query($users)->fetchOne(2));
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

final class RuntimeMappedUser
{
	public function __construct(public int $id, private string $name)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}
}

final class RuntimeUserMapper implements MapperInterface
{
	public static function canMap(mixed $source, MappingOptions $options): bool
	{
		return $source instanceof RuntimeMappedUser
			|| (is_array($source) && isset($source['id'], $source['name']));
	}

	public function map(MappingBranch $context): mixed
	{
		/** @var RuntimeMappedUser|array{id: int, name: string} $source */
		$source = $context->getSource();
		if ($source instanceof RuntimeMappedUser) {
			$context->write('id', $source->id);
			$context->write('name', strtolower($source->getName()));
		} else {
			$context->write('id', $source['id']);
			$context->write('name', strtoupper($source['name']));
		}

		return $context->getResult();
	}
}
