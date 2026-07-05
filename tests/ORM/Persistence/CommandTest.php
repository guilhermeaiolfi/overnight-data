<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class CommandTest extends TestCase
{
	public function testCommandInterfaceOnlyExposesCollection(): void
	{
		self::assertSame(['getCollection'], get_class_methods(CommandInterface::class));
	}

	public function testInsertCommandStoresCollectionAndValues(): void
	{
		$users = $this->users();
		$command = new InsertCommand($users, ['id' => 10, 'name' => 'Ada']);

		self::assertSame($users, $command->getCollection());
		self::assertSame(['id' => 10, 'name' => 'Ada'], $command->getValues());
	}

	public function testUpdateCommandStoresCollectionIdentityAndChanges(): void
	{
		$users = $this->users();
		$command = new UpdateCommand($users, ['tenant_id' => 5, 'id' => 10], ['name' => 'Ada']);

		self::assertSame($users, $command->getCollection());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'Ada'], $command->getChanges());
	}

	public function testUpdateCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand($this->users(), [], ['name' => 'Ada']);
	}

	public function testUpdateCommandRejectsEmptyChanges(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand($this->users(), ['id' => 10], []);
	}

	public function testUpdateCommandRejectsChangesThatIncludeIdentityFields(): void
	{
		$this->expectException(InvalidCommandException::class);

		new UpdateCommand($this->users(), ['tenant_id' => 5, 'id' => 10], ['id' => 11, 'name' => 'Ada']);
	}

	public function testDeleteCommandStoresCollectionAndIdentity(): void
	{
		$users = $this->users();
		$command = new DeleteCommand($users, ['tenant_id' => 5, 'id' => 10]);

		self::assertSame($users, $command->getCollection());
		self::assertSame(['tenant_id' => 5, 'id' => 10], $command->getIdentity());
	}

	public function testDeleteCommandRejectsEmptyIdentity(): void
	{
		$this->expectException(InvalidCommandException::class);

		new DeleteCommand($this->users(), []);
	}

	public function testInsertCommandRejectsValueRef(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);

		new InsertCommand($this->users(), ['id' => $record->getValueRef('id')]);
	}

	public function testUpdateCommandRejectsValueRefInChanges(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);

		new UpdateCommand($this->users(), ['id' => 10], ['name' => $record->getValueRef('name')]);
	}

	public function testDeleteCommandRejectsValueRefInIdentity(): void
	{
		$record = RecordState::new($this->users());

		$this->expectException(InvalidCommandException::class);

		new DeleteCommand($this->users(), ['id' => $record->getValueRef('id')]);
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
		$users = $this->users();
		$first = new InsertCommand($users, ['id' => 10]);
		$second = new DeleteCommand($users, ['id' => 10]);
		$result = new CommandResult(2, ['version' => 3]);
		$executor = new RecordingCommandExecutor($result);

		self::assertSame($result, $executor->execute($first));
		self::assertSame($result, $executor->execute($second));
		self::assertSame([$first, $second], $executor->getCommands());

		$executor->clear();

		self::assertSame([], $executor->getCommands());
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('tenant_id', 'int')->end()
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}
}
