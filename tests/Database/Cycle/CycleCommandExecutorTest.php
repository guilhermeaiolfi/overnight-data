<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\DsnConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleCommandExecutorTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private ?PDO $pdo = null;

	private ?DatabaseInterface $database = null;

	private ?CycleCommandExecutor $executor = null;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-cycle-write-');
		$this->dsn = 'sqlite:' . str_replace('\\', '/', $this->databasePath);
		$this->pdo = new PDO($this->dsn);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->seedDatabase($this->pdo);

		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new DsnConnectionConfig($this->dsn),
				),
			],
		]));

		$this->database = $manager->database('default');
		$this->executor = new CycleCommandExecutor($this->database);
	}

	protected function tearDown(): void
	{
		$this->executor = null;
		$this->database = null;
		$this->pdo = null;
		gc_collect_cycles();

		if (is_file($this->databasePath)) {
			@unlink($this->databasePath);
		}
	}

	public function testInsertCommandInsertsRowAndReturnsEmptyGeneratedValues(): void
	{
		$result = $this->executor->execute(new InsertCommand('users', [
			'id' => 3,
			'name' => 'Linus',
			'email' => 'linus@example.test',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame([], $result->getGeneratedValues());
		self::assertSame(
			['name' => 'Linus', 'email' => 'linus@example.test'],
			$this->fetchUser(3),
		);
	}

	public function testUpdateCommandUpdatesRowBySingleColumnIdentity(): void
	{
		$result = $this->executor->execute(new UpdateCommand('users', ['id' => 1], [
			'name' => 'Ada Lovelace',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame('Ada Lovelace', $this->fetchUser(1)['name']);
	}

	public function testUpdateCommandUpdatesRowByCompositeIdentity(): void
	{
		$result = $this->executor->execute(new UpdateCommand('memberships', [
			'tenant_id' => 1,
			'user_id' => 10,
		], [
			'role' => 'owner',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame('owner', $this->fetchMembershipRole(1, 10));
		self::assertSame('viewer', $this->fetchMembershipRole(2, 10));
	}

	public function testDeleteCommandDeletesRowBySingleColumnIdentity(): void
	{
		$result = $this->executor->execute(new DeleteCommand('users', ['id' => 2]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertNull($this->fetchUser(2));
		self::assertNotNull($this->fetchUser(1));
	}

	public function testDeleteCommandDeletesRowByCompositeIdentity(): void
	{
		$result = $this->executor->execute(new DeleteCommand('memberships', [
			'tenant_id' => 2,
			'user_id' => 10,
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertNull($this->fetchMembershipRole(2, 10));
		self::assertSame('admin', $this->fetchMembershipRole(1, 10));
	}

	public function testUnsupportedCommandImplementationThrows(): void
	{
		$command = new class () implements CommandInterface {
			public function getCollectionName(): string
			{
				return 'users';
			}
		};

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage($command::class);

		$this->executor->execute($command);
	}

	public function testExecutorDoesNotDependOnOrmRuntimeStateClasses(): void
	{
		$source = $this->executorSource();

		self::assertStringNotContainsString('ON\\Data\\ORM\\State\\', $source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Sync\\', $source);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Session', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('FlushExecutor', $source);
		self::assertStringNotContainsString('Registry', $source);
	}

	public function testExecutorUsesCycleBuildersInsteadOfManualSqlGeneration(): void
	{
		$source = $this->executorSource();

		self::assertStringContainsString('->insert(', $source);
		self::assertStringContainsString('->update(', $source);
		self::assertStringContainsString('->delete(', $source);
		self::assertStringNotContainsString('sqlStatement', $source);
		self::assertStringNotContainsString('->execute(', $source);
		self::assertStringNotContainsString('SELECT ', $source);
		self::assertStringNotContainsString('INSERT ', $source);
		self::assertStringNotContainsString('UPDATE ', $source);
		self::assertStringNotContainsString('DELETE ', $source);
	}

	/**
	 * @return array{name: string, email: string}|null
	 */
	private function fetchUser(int $id): ?array
	{
		$statement = $this->pdo->prepare('SELECT name, email FROM users WHERE id = ?');
		$statement->execute([$id]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function fetchMembershipRole(int $tenantId, int $userId): ?string
	{
		$statement = $this->pdo->prepare('SELECT role FROM memberships WHERE tenant_id = ? AND user_id = ?');
		$statement->execute([$tenantId, $userId]);
		$role = $statement->fetchColumn();

		return is_string($role) ? $role : null;
	}

	private function executorSource(): string
	{
		$reflection = new ReflectionClass(CycleCommandExecutor::class);
		$fileName = $reflection->getFileName();

		self::assertIsString($fileName);

		return (string) file_get_contents($fileName);
	}

	private function seedDatabase(PDO $pdo): void
	{
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
		$pdo->exec('CREATE TABLE memberships (tenant_id INTEGER, user_id INTEGER, role TEXT, PRIMARY KEY (tenant_id, user_id))');

		$users = $pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)');
		$users->execute([1, 'Ada', 'ada@example.test']);
		$users->execute([2, 'Grace', 'grace@example.test']);

		$memberships = $pdo->prepare('INSERT INTO memberships (tenant_id, user_id, role) VALUES (?, ?, ?)');
		$memberships->execute([1, 10, 'admin']);
		$memberships->execute([2, 10, 'viewer']);
	}
}
