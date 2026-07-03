<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidWriteCommandException;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Persistence\WriteCommandKind;
use ON\Data\ORM\Persistence\WriteResult;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\RecordingWriteExecutor;

final class WriteCommandTest extends TestCase
{
	public function testInsertCommandStoresCollectionNameKindAndValues(): void
	{
		$command = new InsertCommand('users', ['id' => 10, 'name' => 'Ada']);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(WriteCommandKind::INSERT, $command->getKind());
		self::assertSame(['id' => 10, 'name' => 'Ada'], $command->getValues());
	}

	public function testUpdateCommandStoresCollectionNameKindIdentityAndChanges(): void
	{
		$command = new UpdateCommand('users', ['tenant_id' => 5, 'id' => 10], ['name' => 'Ada']);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(WriteCommandKind::UPDATE, $command->getKind());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'Ada'], $command->getChanges());
	}

	public function testUpdateCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidWriteCommandException::class);

		new UpdateCommand('users', [], ['name' => 'Ada']);
	}

	public function testUpdateCommandRejectsEmptyChanges(): void
	{
		$this->expectException(InvalidWriteCommandException::class);

		new UpdateCommand('users', ['id' => 10], []);
	}

	public function testUpdateCommandRejectsChangesThatIncludeIdentityFields(): void
	{
		$this->expectException(InvalidWriteCommandException::class);

		new UpdateCommand('users', ['tenant_id' => 5, 'id' => 10], ['id' => 11, 'name' => 'Ada']);
	}

	public function testDeleteCommandStoresCollectionNameKindAndIdentity(): void
	{
		$command = new DeleteCommand('users', ['tenant_id' => 5, 'id' => 10]);

		self::assertSame('users', $command->getCollectionName());
		self::assertSame(WriteCommandKind::DELETE, $command->getKind());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
	}

	public function testDeleteCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidWriteCommandException::class);

		new DeleteCommand('users', []);
	}

	public function testWriteResultStoresAffectedRowsAndGeneratedValues(): void
	{
		$result = new WriteResult(1, ['id' => 10]);

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 10], $result->getGeneratedValues());
	}

	public function testWriteResultRejectsNegativeAffectedRows(): void
	{
		$this->expectException(InvalidWriteCommandException::class);

		new WriteResult(-1);
	}

	public function testRecordingWriteExecutorRecordsCommandOrder(): void
	{
		$first = new InsertCommand('users', ['id' => 10]);
		$second = new DeleteCommand('users', ['id' => 10]);
		$result = new WriteResult(2, ['version' => 3]);
		$executor = new RecordingWriteExecutor($result);

		self::assertSame($result, $executor->execute($first));
		self::assertSame($result, $executor->execute($second));
		self::assertSame([$first, $second], $executor->getCommands());

		$executor->clear();

		self::assertSame([], $executor->getCommands());
	}
}
