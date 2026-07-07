<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DataRuntimeTest extends TestCase
{
	public function testQueryUsesInjectedQueryExecutor(): void
	{
		$executor = new RecordingQueryExecutor([['id' => 1, 'name' => 'Ada']]);
		$commandExecutor = new RecordingRuntimeCommandExecutor();
		$runtime = new DataRuntime($executor, $commandExecutor);
		$users = (new Registry())
			->collection('users')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$query = $runtime->query($users);
		$rows = $query->select($query->id, $query->name)->fetchAll();

		self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);
		self::assertSame($query, $executor->lastQuery);
	}

	public function testGetCommandExecutorReturnsInjectedExecutor(): void
	{
		$queryExecutor = new RecordingQueryExecutor();
		$commandExecutor = new RecordingRuntimeCommandExecutor();
		$runtime = new DataRuntime($queryExecutor, $commandExecutor);

		self::assertSame($commandExecutor, $runtime->getCommandExecutor());
	}

	public function testSourceDoesNotReferenceAdapterNamespaces(): void
	{
		$reflection = new ReflectionClass(DataRuntime::class);
		$fileName = $reflection->getFileName();

		self::assertIsString($fileName);
		$source = (string) file_get_contents($fileName);

		foreach ([
			'Cycle\\',
			'Doctrine\\',
			'ConnectionConfig',
			'CycleConnectionFactory',
			'CycleQueryExecutor',
			'CycleCommandExecutor',
			'DoctrineQueryExecutor',
			'DoctrineCommandExecutor',
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $source);
		}

		self::assertFalse(method_exists(DataRuntime::class, 'connect'));
		self::assertFalse(method_exists(DataRuntime::class, 'fromCycle'));
	}
}

final class RecordingQueryExecutor implements QueryExecutorInterface
{
	public ?SelectQuery $lastQuery = null;

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct(
		private readonly array $rows = [],
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		$this->lastQuery = $query;

		return $this->rows;
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$this->lastQuery = $query;

		return $this->rows[0] ?? null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		$this->lastQuery = $query;

		return $this->rows;
	}
}

final class RecordingRuntimeCommandExecutor implements CommandExecutorInterface
{
	public ?CommandInterface $lastCommand = null;

	public function execute(CommandInterface $command): CommandResult
	{
		$this->lastCommand = $command;

		return new CommandResult(1);
	}
}
