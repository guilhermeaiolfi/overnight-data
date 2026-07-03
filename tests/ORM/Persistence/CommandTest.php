<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class CommandTest extends TestCase
{
	public function testInsertCommandStoresCollectionNameAndValues(): void
	{
		$command = new InsertCommand('users', ['id' => 10, 'name' => 'Ada']);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(['id' => 10, 'name' => 'Ada'], $command->getValues());
	}

	public function testUpdateCommandStoresCollectionNameIdentityAndChanges(): void
	{
		$command = new UpdateCommand('users', ['tenant_id' => 5, 'id' => 10], ['name' => 'Ada']);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'Ada'], $command->getChanges());
	}

	public function testUpdateCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand('users', [], ['name' => 'Ada']);
	}

	public function testUpdateCommandRejectsEmptyChanges(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand('users', ['id' => 10], []);
	}

	public function testUpdateCommandRejectsChangesThatIncludeIdentityFields(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand('users', ['tenant_id' => 5, 'id' => 10], ['id' => 11, 'name' => 'Ada']);
	}

	public function testDeleteCommandStoresCollectionNameAndIdentity(): void
	{
		$command = new DeleteCommand('users', ['tenant_id' => 5, 'id' => 10]);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
	}

	public function testDeleteCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidCommandException::class);

		new DeleteCommand('users', []);
	}

	public function testCommandResultStoresAffectedRowsAndGeneratedValues(): void
	{
		$result = new CommandResult(1, ['id' => 10]);

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 10], $result->getGeneratedValues());
	}

	public function testCommandResultRejectsNegativeAffectedRows(): void
	{
		$this->expectException(InvalidCommandException::class);

		new CommandResult(-1);
	}

	public function testRecordingCommandExecutorRecordsCommandOrder(): void
	{
		$first = new InsertCommand('users', ['id' => 10]);
		$second = new DeleteCommand('users', ['id' => 10]);
		$result = new CommandResult(2, ['version' => 3]);
		$executor = new RecordingCommandExecutor($result);

		self::assertSame($result, $executor->execute($first));
		self::assertSame($result, $executor->execute($second));
		self::assertSame([$first, $second], $executor->getCommands());

		$executor->clear();

		self::assertSame([], $executor->getCommands());
	}
}
