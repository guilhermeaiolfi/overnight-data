<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\DsnConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\RecordFlusher;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
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

	private ?CollectionInterface $users = null;

	private ?CollectionInterface $memberships = null;

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
		$this->users = $this->makeUsers();
		$this->memberships = $this->makeMemberships();
	}

	protected function tearDown(): void
	{
		$this->executor = null;
		$this->database = null;
		$this->pdo = null;
		$this->users = null;
		$this->memberships = null;
		gc_collect_cycles();

		if (is_file($this->databasePath)) {
			@unlink($this->databasePath);
		}
	}

	public function testInsertCommandUsesCollectionTableAndMapsFieldNamesToColumns(): void
	{
		$result = $this->executor->execute(new InsertCommand($this->users(), [
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

	public function testInsertCommandReturnsGeneratedAutoIncrementPrimaryKeyValueByFieldName(): void
	{
		$result = $this->executor->execute(new InsertCommand($this->users(), [
			'name' => 'Katherine',
			'email' => 'katherine@example.test',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 3], $result->getGeneratedValues());
		self::assertSame(
			['name' => 'Katherine', 'email' => 'katherine@example.test'],
			$this->fetchUser(3),
		);
	}

	public function testInsertCommandWithApplicationAssignedPrimaryKeyReturnsNoGeneratedValues(): void
	{
		$result = $this->executor->execute(new InsertCommand($this->users(), [
			'id' => 20,
			'name' => 'Margaret',
			'email' => 'margaret@example.test',
		]));

		self::assertSame([], $result->getGeneratedValues());
		self::assertSame(
			['name' => 'Margaret', 'email' => 'margaret@example.test'],
			$this->fetchUser(20),
		);
	}

	public function testInsertCommandWithNonAutoIncrementPrimaryKeyReturnsNoGeneratedValues(): void
	{
		$result = $this->executor->execute(new InsertCommand($this->makeExternalUsers(), [
			'id' => 'external-1',
			'name' => 'External',
		]));

		self::assertSame([], $result->getGeneratedValues());
	}

	public function testInsertCommandWithCompositePrimaryKeyReturnsNoGeneratedValues(): void
	{
		$result = $this->executor->execute(new InsertCommand($this->memberships(), [
			'tenantId' => 3,
			'userId' => 30,
			'role' => 'member',
		]));

		self::assertSame([], $result->getGeneratedValues());
		self::assertSame('member', $this->fetchMembershipRole(3, 30));
	}

	public function testRecordFlusherMergesGeneratedAutoIncrementPrimaryKeyValue(): void
	{
		$users = $this->users();
		$record = RecordState::new($users, [
			'name' => 'Dorothy',
			'email' => 'dorothy@example.test',
		]);
		$states = new RecordStateMap();
		$states->add($record);

		(new RecordFlusher($this->executor))->flush($states);

		$key = $users->getKey(3);
		self::assertTrue($record->isClean());
		self::assertSame(['name' => 'Dorothy', 'email' => 'dorothy@example.test', 'id' => 3], $record->getValues());
		self::assertSame($record, $states->getByKey($key));
	}

	public function testUpdateCommandUsesCollectionTableAndMapsChangedFieldsToColumns(): void
	{
		$result = $this->executor->execute(new UpdateCommand($this->users(), ['id' => 1], [
			'name' => 'Ada Lovelace',
			'email' => 'ada@lovelace.test',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(
			['name' => 'Ada Lovelace', 'email' => 'ada@lovelace.test'],
			$this->fetchUser(1),
		);
	}

	public function testUpdateCommandMapsSingleIdentityFieldToPrimaryKeyColumn(): void
	{
		$result = $this->executor->execute(new UpdateCommand($this->users(), ['id' => 2], [
			'name' => 'Grace Hopper',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame('Grace Hopper', $this->fetchUser(2)['name']);
		self::assertSame('Ada', $this->fetchUser(1)['name']);
	}

	public function testUpdateCommandMapsCompositeIdentityFieldsToCustomPrimaryKeyColumns(): void
	{
		$result = $this->executor->execute(new UpdateCommand($this->memberships(), [
			'tenantId' => 1,
			'userId' => 10,
		], [
			'role' => 'owner',
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame('owner', $this->fetchMembershipRole(1, 10));
		self::assertSame('viewer', $this->fetchMembershipRole(2, 10));
	}

	public function testDeleteCommandMapsSingleIdentityFieldToPrimaryKeyColumn(): void
	{
		$result = $this->executor->execute(new DeleteCommand($this->users(), ['id' => 2]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertNull($this->fetchUser(2));
		self::assertNotNull($this->fetchUser(1));
	}

	public function testDeleteCommandMapsCompositeIdentityFieldsToCustomPrimaryKeyColumns(): void
	{
		$result = $this->executor->execute(new DeleteCommand($this->memberships(), [
			'tenantId' => 2,
			'userId' => 10,
		]));

		self::assertInstanceOf(CommandResult::class, $result);
		self::assertSame(1, $result->getAffectedRows());
		self::assertNull($this->fetchMembershipRole(2, 10));
		self::assertSame('admin', $this->fetchMembershipRole(1, 10));
	}

	public function testUnknownInsertFieldThrows(): void
	{
		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage("collection 'users'");
		$this->expectExceptionMessage("field 'unknown'");

		$this->executor->execute(new InsertCommand($this->users(), [
			'id' => 3,
			'unknown' => 'value',
		]));
	}

	public function testRejectsInsertCommandWithUnresolvedValueRefBeforeSqlBuilderUse(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Insert command');
		$this->expectExceptionMessage('values');
		$this->expectExceptionMessage('users.id');

		$this->executor->execute(new InsertCommand($this->users(), [
			'id' => $record->getValueRef('id'),
			'name' => 'Unready',
			'email' => 'unready@example.test',
		]));
	}

	public function testRejectsUpdateCommandWithUnresolvedValueRefInIdentity(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Update command');
		$this->expectExceptionMessage('identity');
		$this->expectExceptionMessage('users.id');

		$this->executor->execute(new UpdateCommand($this->users(), [
			'id' => $record->getValueRef('id'),
		], [
			'name' => 'Unready',
		]));
	}

	public function testRejectsUpdateCommandWithUnresolvedValueRefInChanges(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Update command');
		$this->expectExceptionMessage('changes');
		$this->expectExceptionMessage('users.name');

		$this->executor->execute(new UpdateCommand($this->users(), [
			'id' => 1,
		], [
			'name' => $record->getValueRef('name'),
		]));
	}

	public function testRejectsDeleteCommandWithUnresolvedValueRefInIdentity(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Delete command');
		$this->expectExceptionMessage('identity');
		$this->expectExceptionMessage('users.id');

		$this->executor->execute(new DeleteCommand($this->users(), [
			'id' => $record->getValueRef('id'),
		]));
	}

	public function testUnknownUpdateChangeFieldThrows(): void
	{
		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage("collection 'users'");
		$this->expectExceptionMessage("field 'unknown'");

		$this->executor->execute(new UpdateCommand($this->users(), ['id' => 1], [
			'unknown' => 'value',
		]));
	}

	public function testUnknownIdentityFieldThrows(): void
	{
		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage("collection 'users'");
		$this->expectExceptionMessage("field 'unknown'");

		$this->executor->execute(new DeleteCommand($this->users(), ['unknown' => 1]));
	}

	public function testUnsupportedCommandImplementationThrows(): void
	{
		$users = $this->users();
		$command = new class ($users) implements CommandInterface {
			public function __construct(
				private readonly CollectionInterface $collection,
			) {
			}

			public function getCollection(): CollectionInterface
			{
				return $this->collection;
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
		$statement = $this->pdo->prepare('SELECT full_name AS name, email_address AS email FROM app_users WHERE user_id = ?');
		$statement->execute([$id]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function fetchMembershipRole(int $tenantId, int $userId): ?string
	{
		$statement = $this->pdo->prepare('SELECT role_name FROM app_memberships WHERE tenant_key = ? AND member_key = ?');
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

	private function users(): CollectionInterface
	{
		self::assertInstanceOf(CollectionInterface::class, $this->users);

		return $this->users;
	}

	private function memberships(): CollectionInterface
	{
		self::assertInstanceOf(CollectionInterface::class, $this->memberships);

		return $this->memberships;
	}

	private function makeUsers(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->table('app_users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->autoIncrement(true)->end()
			->field('name', 'string')->column('full_name')->end()
			->field('email', 'string')->column('email_address')->end();
	}

	private function makeExternalUsers(): CollectionInterface
	{
		return (new Registry())
			->collection('external_users')
			->table('app_external_users')
			->primaryKey('id')
			->field('id', 'string')->column('external_id')->end()
			->field('name', 'string')->column('full_name')->end();
	}

	private function makeMemberships(): CollectionInterface
	{
		return (new Registry())
			->collection('memberships')
			->table('app_memberships')
			->primaryKey('tenantId', 'userId')
			->field('tenantId', 'int')->column('tenant_key')->end()
			->field('userId', 'int')->column('member_key')->end()
			->field('role', 'string')->column('role_name')->end();
	}

	private function seedDatabase(PDO $pdo): void
	{
		$pdo->exec('CREATE TABLE app_users (user_id INTEGER PRIMARY KEY, full_name TEXT, email_address TEXT)');
		$pdo->exec('CREATE TABLE app_external_users (external_id TEXT PRIMARY KEY, full_name TEXT)');
		$pdo->exec('CREATE TABLE app_memberships (tenant_key INTEGER, member_key INTEGER, role_name TEXT, PRIMARY KEY (tenant_key, member_key))');

		$users = $pdo->prepare('INSERT INTO app_users (user_id, full_name, email_address) VALUES (?, ?, ?)');
		$users->execute([1, 'Ada', 'ada@example.test']);
		$users->execute([2, 'Grace', 'grace@example.test']);

		$memberships = $pdo->prepare('INSERT INTO app_memberships (tenant_key, member_key, role_name) VALUES (?, ?, ?)');
		$memberships->execute([1, 10, 'admin']);
		$memberships->execute([2, 10, 'viewer']);
	}
}
