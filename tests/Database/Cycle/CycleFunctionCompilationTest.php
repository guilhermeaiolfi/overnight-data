<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\Query\QueryParameters;
use ON\Data\Database\Cycle\ConnectionConfig;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use ON\Data\Database\DatabaseFamily;
use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\QueryFunction\CompiledExpression;
use ON\Data\Query\QueryFunction\FunctionArguments;
use ON\Data\Query\QueryFunction\FunctionCompilationContextInterface;
use ON\Data\Query\QueryFunction\FunctionCompilationException;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;
use ON\Data\Query\QueryFunction\Standard\Temporal\DateValue;
use ON\Data\Query\QueryFunction\Standard\Temporal\Day;
use ON\Data\Query\QueryFunction\Standard\Temporal\Hour;
use ON\Data\Query\QueryFunction\Standard\Temporal\Month;
use ON\Data\Query\QueryFunction\Standard\Temporal\Year;
use ON\Data\Query\QueryFunction\UnsupportedQueryFunctionException;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleFunctionCompilationTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private Registry $registry;

	private DataRuntime $database;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-fn-');
		$this->dsn = 'sqlite:' . str_replace('\\', '/', $this->databasePath);
		$this->registry = $this->makeRegistry();
		$this->seedDatabase();
		$this->database = (new CycleRuntimeFactory())->connect(ConnectionConfig::dsn('sqlite', $this->dsn));
	}

	protected function tearDown(): void
	{
		gc_collect_cycles();

		if (is_file($this->databasePath)) {
			@unlink($this->databasePath);
		}
	}

	public function testUserFunctionCanDelegateToConcat(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$rows = $users
			->select(
				$users->id,
				x()->fn()->call(DelegatingFullName::class, $users->firstName, $users->lastName)->as('full_name'),
			)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'full_name' => 'Ada Lovelace'],
			['id' => 2, 'full_name' => 'Grace Hopper'],
		], $rows);
	}

	public function testUserFunctionCanEmitPlatformSql(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$rows = $users
			->select(
				$users->id,
				x()->fn()->call(PlatformUpper::class, $users->firstName)->as('upper_name'),
			)
			->where(x()->eq($users->id, 1))
			->fetchAll();

		self::assertSame([['id' => 1, 'upper_name' => 'ADA']], $rows);
	}

	public function testNestedFunctionsAndTemporalStandardsCompile(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$rows = $users
			->select(
				$users->id,
				x()->fn()->call(Year::class, $users->createdAt)->as('year'),
				x()->fn()->call(Month::class, $users->createdAt)->as('month'),
				x()->fn()->call(Day::class, $users->createdAt)->as('day'),
				x()->fn()->call(Hour::class, $users->createdAt)->as('hour'),
				x()->fn()->call(DateValue::class, $users->createdAt)->as('date'),
			)
			->where(x()->eq($users->id, 1))
			->fetchAll();

		self::assertSame([[
			'id' => 1,
			'year' => 2026,
			'month' => 6,
			'day' => 24,
			'hour' => 10,
			'date' => '2026-06-24',
		]], $rows);

		$nested = $this->database->query($this->registry->getCollection('users'));
		$sql = $this->compileSql(
			$nested
				->select(x()->fn()->call(Year::class, x()->fn()->call(DateValue::class, $nested->createdAt))->as('year'))
				->where(x()->eq($nested->id, 1)),
		);
		self::assertStringContainsString("strftime('%Y'", $sql);
		self::assertStringContainsString('date(', $sql);
	}

	public function testFunctionInCorrelatedSubqueryWithAncestorField(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$orders = $this->database->query($this->registry->getCollection('orders'));

		$rows = $users
			->select($users->firstName)
			->where(x()->exists(
				$orders
					->where(
						x()->eq($orders->userId, $users->id),
						x()->eq(x()->fn()->call(Year::class, $orders->createdAt), 2026),
					),
			))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([['firstName' => 'Ada']], $rows);
	}

	public function testUnusualColumnNamesAreQuotedAndParametersStayOrdered(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$query = $users
			->select(
				x()->fn()->call(Year::class, $users->oddCreated)->as('year'),
				x()->fn()->call(PlatformLiteralProbe::class, 'probe')->as('probe'),
			)
			->where(x()->eq($users->id, 1));

		$sql = $this->compileSql($query);
		self::assertStringContainsString('"odd-created"', $sql);
		self::assertStringContainsString('?', $sql);

		$rows = $query->fetchAll();
		self::assertSame([['year' => 2025, 'probe' => 'probe']], $rows);
	}

	public function testRecursiveFunctionCompilationIsRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$expression = x()->fn()->call(RecursiveSelf::class, $users->createdAt);
		RecursiveSelf::$node = $expression;

		try {
			$this->expectException(FunctionCompilationException::class);
			$this->expectExceptionMessage('Recursive compilation');
			$this->compileSql($users->select($expression->as('value')));
		} finally {
			RecursiveSelf::$node = null;
		}
	}

	public function testUnsupportedPlatformErrorsIdentifyFunctionAndPlatform(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		try {
			$this->compileSql(
				$users->select(x()->fn()->call(SqlServerOnly::class, $users->createdAt)->as('value')),
			);
			self::fail('Expected unsupported platform exception.');
		} catch (UnsupportedQueryFunctionException $exception) {
			self::assertStringContainsString(SqlServerOnly::class, $exception->getMessage());
			self::assertStringContainsString(DatabaseFamily::Sqlite->value, $exception->getMessage());
		}
	}

	public function testSharedFunctionContractsDoNotImportCycle(): void
	{
		$files = [
			dirname(__DIR__, 3) . '/src/Query/QueryFunction/QueryFunctionInterface.php',
			dirname(__DIR__, 3) . '/src/Query/QueryFunction/FunctionCompilationContextInterface.php',
			dirname(__DIR__, 3) . '/src/Query/QueryFunction/CompiledExpression.php',
			dirname(__DIR__, 3) . '/src/Database/DatabasePlatformInterface.php',
			dirname(__DIR__, 3) . '/src/Database/DatabaseFamily.php',
		];

		foreach ($files as $file) {
			$contents = (string) file_get_contents($file);
			self::assertStringNotContainsString('Cycle\\', $contents, $file);
		}
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->table('users');
		$users->field('id', 'int');
		$users->field('firstName', 'string')->column('first_name');
		$users->field('lastName', 'string')->column('last_name');
		$users->field('createdAt', 'datetime')->column('created_at');
		$users->field('oddCreated', 'datetime')->column('odd-created');
		$users->primaryKey('id');

		$orders = $registry->collection('orders');
		$orders->table('orders');
		$orders->field('id', 'int');
		$orders->field('userId', 'int')->column('user_id');
		$orders->field('createdAt', 'datetime')->column('created_at');
		$orders->primaryKey('id');

		return $registry;
	}

	private function seedDatabase(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, created_at TEXT, "odd-created" TEXT)');
		$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, created_at TEXT)');
		$pdo->exec("INSERT INTO users (id, first_name, last_name, created_at, \"odd-created\") VALUES (1, 'Ada', 'Lovelace', '2026-06-24 10:15:00', '2025-01-02 03:04:05'), (2, 'Grace', 'Hopper', '2024-01-01 00:00:00', '2024-01-01 00:00:00')");
		$pdo->exec("INSERT INTO orders (id, user_id, created_at) VALUES (1, 1, '2026-03-01 12:00:00'), (2, 2, '2024-03-01 12:00:00')");
	}

	private function compileSql(SelectQuery $query): string
	{
		$databaseReflection = new ReflectionClass($this->database);
		$executorProperty = $databaseReflection->getProperty('queryExecutor');
		$executor = $executorProperty->getValue($this->database);

		$executorReflection = new ReflectionClass($executor);
		$translatorProperty = $executorReflection->getProperty('translator');
		$translator = $translatorProperty->getValue($executor);

		$translated = $translator->translate($query);
		$parameters = new QueryParameters();

		return $translated->query()->sqlStatement($parameters);
	}
}

final class DelegatingFullName implements QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		return $context->compile(x()->concat(
			$arguments->expression(0),
			' ',
			$arguments->expression(1),
		));
	}
}

final class PlatformUpper implements QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		$value = $context->compile($arguments->expression(0));

		return $context->sql('upper(' . $value->getSql() . ')', $value->getParameters());
	}
}

final class PlatformLiteralProbe implements QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		$literal = $context->compile($arguments->expression(0));

		return $context->sql($literal->getSql(), $literal->getParameters());
	}
}

final class RecursiveSelf implements QueryFunctionInterface
{
	public static ?FunctionCallExpression $node = null;

	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		return $context->compile(self::$node ?? x()->fn()->call(self::class, $arguments->expression(0)));
	}
}

final class SqlServerOnly implements QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		if ($context->platform()->family() !== DatabaseFamily::SqlServer) {
			throw UnsupportedQueryFunctionException::forPlatform(self::class, $context->platform()->family());
		}

		$value = $context->compile($arguments->expression(0));

		return $context->sql('YEAR(' . $value->getSql() . ')', $value->getParameters());
	}
}
