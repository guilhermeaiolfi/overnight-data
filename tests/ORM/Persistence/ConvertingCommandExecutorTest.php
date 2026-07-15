<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use DateTimeImmutable;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\ConvertingCommandExecutor;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Persistence\UpdateCommand;
use PHPUnit\Framework\TestCase;

final class ConvertingCommandExecutorTest extends TestCase
{
	public function testExecuteProjectsPhpValuesToStorageWithoutMutatingOriginalCommand(): void
	{
		$articles = (new Registry())
			->collection('articles')
			->table('articles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('publishedAt', 'datetime')->end()
			->field('meta', 'json')->end();

		$publishedAt = new DateTimeImmutable('2026-06-18 13:45:12');
		$meta = ['tags' => ['php']];
		$command = new InsertCommand($articles, [
			'id' => 1,
			'publishedAt' => $publishedAt,
			'meta' => $meta,
		]);

		$inner = new class () implements \ON\Data\ORM\Persistence\CommandExecutorInterface {
			public ?CommandInterface $lastCommand = null;

			public function execute(CommandInterface $command): CommandResult
			{
				$this->lastCommand = $command;

				return new CommandResult(1);
			}
		};

		$executor = new ConvertingCommandExecutor($inner);
		$executor->execute($command);

		self::assertSame($publishedAt, $command->getValues()['publishedAt']);
		self::assertSame($meta, $command->getValues()['meta']);

		self::assertInstanceOf(InsertCommand::class, $inner->lastCommand);
		self::assertNotSame($command, $inner->lastCommand);
		self::assertSame('2026-06-18 13:45:12', $inner->lastCommand->getValues()['publishedAt']);
		self::assertSame('{"tags":["php"]}', $inner->lastCommand->getValues()['meta']);
	}

	public function testTransactionDelegatesToTransactionalInnerExecutor(): void
	{
		$inner = new class () implements \ON\Data\ORM\Persistence\CommandExecutorInterface, TransactionalCommandExecutorInterface {
			public bool $transactionCalled = false;

			public function execute(CommandInterface $command): CommandResult
			{
				return new CommandResult(0);
			}

			public function transaction(callable $callback): mixed
			{
				$this->transactionCalled = true;

				return $callback();
			}
		};

		$executor = new ConvertingCommandExecutor($inner);
		$result = $executor->transaction(static fn (): string => 'ok');

		self::assertTrue($inner->transactionCalled);
		self::assertSame('ok', $result);
	}

	public function testUpdateConvertsIdentityAndChanges(): void
	{
		$articles = (new Registry())
			->collection('articles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('publishedAt', 'datetime')->end();

		$command = new UpdateCommand(
			$articles,
			['id' => 1],
			['publishedAt' => new DateTimeImmutable('2026-07-15 09:30:00')],
		);

		$inner = new class () implements \ON\Data\ORM\Persistence\CommandExecutorInterface {
			public ?CommandInterface $lastCommand = null;

			public function execute(CommandInterface $command): CommandResult
			{
				$this->lastCommand = $command;

				return new CommandResult(1);
			}
		};

		(new ConvertingCommandExecutor($inner))->execute($command);

		self::assertInstanceOf(UpdateCommand::class, $inner->lastCommand);
		self::assertSame(['id' => 1], $inner->lastCommand->getIdentity());
		self::assertSame(
			['publishedAt' => '2026-07-15 09:30:00'],
			$inner->lastCommand->getChanges(),
		);
	}
}
