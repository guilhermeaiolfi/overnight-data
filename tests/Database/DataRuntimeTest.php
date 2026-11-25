<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database;

use InvalidArgumentException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriter;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\Fixture\AbstractUserDto;

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

	public function testQueryUsesCustomWriterForInterfaceTarget(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(RuntimeUserWriter::class);
		$runtime = new DataRuntime(
			new RecordingQueryExecutor([['id' => 1, 'name' => 'Ada']]),
			new RecordingRuntimeCommandExecutor(),
			$gateway,
		);
		$users = (new Registry())->collection('users')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$rows = $runtime->query($users)->to(RuntimeUserInterface::class)->fetchAll();

		self::assertCount(1, $rows);
		self::assertInstanceOf(RuntimeUserInterface::class, $rows[0]);
		self::assertSame('Ada', $rows[0]->getName());
	}

	public function testQueryKeepsDefaultProjectionForAbstractCustomWriterTarget(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(RuntimeUserWriter::class);
		$runtime = new DataRuntime(
			new RecordingQueryExecutor([['id' => 1, 'name' => 'Ada']]),
			new RecordingRuntimeCommandExecutor(),
			$gateway,
		);
		$users = (new Registry())->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$query = $runtime->query($users)->to(AbstractUserDto::class);
		self::assertSame(['id', 'name'], $query->projection()->getPublicScalarPaths());

		$rows = $query->fetchAll();
		self::assertCount(1, $rows);
		self::assertInstanceOf(AbstractUserDto::class, $rows[0]);
		self::assertSame(1, $rows[0]->id);
	}

	public function testWritableQueryUsesCustomWriterAndMapperForEntity(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeUserWriter::class)->prepend(RuntimeUserMapper::class);
		$commands = new RecordingRuntimeCommandExecutor();
		$runtime = new DataRuntime(new RecordingQueryExecutor([['id' => 1, 'name' => 'Ada']]), $commands, $gateway);
		$users = (new Registry())->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$session = $runtime->session();

		$rows = $runtime->query($users)->to(RuntimeCustomEntity::class)->writable($session)->fetchAll();

		self::assertCount(1, $rows);
		self::assertInstanceOf(RuntimeCustomEntity::class, $rows[0]);
		self::assertSame(1, $rows[0]->id);
		self::assertSame('Ada', $rows[0]->getName());
		$rows[0]->rename('Ada Lovelace');
		$session->flush();

		self::assertInstanceOf(UpdateCommand::class, $commands->lastCommand);
		self::assertSame(['id' => 1], $commands->lastCommand->getIdentity());
		self::assertSame(['name' => 'Ada Lovelace'], $commands->lastCommand->getChanges());
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
			'ConnectionInterface',
			'SqlRuntimeFactory',
			'SqlQueryExecutor',
			'SqlCommandExecutor',
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

final class RecordingRuntimeCommandExecutor implements TransactionalCommandExecutorInterface
{
	public ?CommandInterface $lastCommand = null;

	public function execute(CommandInterface $command): CommandResult
	{
		$this->lastCommand = $command;

		return new CommandResult(1);
	}

	public function isInTransaction(): ?bool
	{
		return null;
	}

	public function transaction(callable $callback): mixed
	{
		return $callback();
	}
}

interface RuntimeUserInterface
{
	public function getName(): string;
}

final class RuntimeCustomEntity implements RuntimeUserInterface
{
	public function __construct(
		#[Hidden]
		public readonly int $id,
		private string $name,
	) {
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function rename(string $name): void
	{
		$this->name = $name;
	}
}

final class RuntimeUserWriter implements WriterInterface
{
	private readonly ArrayWriter $writer;

	public function __construct()
	{
		$this->writer = new ArrayWriter();
	}

	public static function canWrite(mixed $target, MappingOptions $options): bool
	{
		return in_array($target, [RuntimeUserInterface::class, RuntimeCustomEntity::class, AbstractUserDto::class], true);
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		return $this->writer->createState($node);
	}

	public function write(WriterStateInterface $state, string|int $name, mixed $value, MappingNode $node): void
	{
		$this->writer->write($state, $name, $value, $node);
	}

	public function getResult(WriterStateInterface $state, MappingNode $node): RuntimeCustomEntity|AbstractUserDto
	{
		$row = $this->writer->getResult($state, $node);
		$id = $row['id'] ?? null;
		$name = $row['name'] ?? null;
		if (! is_int($id) || ! is_string($name)) {
			throw new InvalidArgumentException('Expected an integer id and a string name.');
		}

		if ($node->getTarget() === AbstractUserDto::class) {
			return new class ($id) extends AbstractUserDto {
				public function __construct(int $id)
				{
					$this->id = $id;
				}
			};
		}

		return new RuntimeCustomEntity($id, $name);
	}
}

final class RuntimeUserMapper implements MapperInterface
{
	public static function canMap(mixed $source, MappingOptions $options): bool
	{
		return $source instanceof RuntimeCustomEntity;
	}

	public function map(MappingBranch $context): mixed
	{
		$source = $context->getSource();
		if (! $source instanceof RuntimeCustomEntity) {
			throw new InvalidArgumentException('Expected a runtime entity.');
		}

		$context->write('id', $source->id);
		$context->write('name', $source->getName());

		return $context->getResult();
	}
}
