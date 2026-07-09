<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class CommandTest extends TestCase
{
	use OrmFixture;

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

	public function testInsertCommandAllowsValueRef(): void
	{
		$record = RecordState::new($this->users());
		$ref = $record->getValueRef('id');

		$command = new InsertCommand($this->users(), ['id' => $ref]);

		self::assertSame($ref, $command->getValues()['id']);
	}

	public function testUpdateCommandAllowsValueRefInIdentity(): void
	{
		$record = RecordState::new($this->users());
		$ref = $record->getValueRef('id');

		$command = new UpdateCommand($this->users(), ['id' => $ref], ['name' => 'Ada']);

		self::assertSame($ref, $command->getIdentity()['id']);
	}

	public function testUpdateCommandAllowsValueRefInChanges(): void
	{
		$record = RecordState::new($this->users());
		$ref = $record->getValueRef('name');

		$command = new UpdateCommand($this->users(), ['id' => 10], ['name' => $ref]);

		self::assertSame($ref, $command->getChanges()['name']);
	}

	public function testDeleteCommandAllowsValueRefInIdentity(): void
	{
		$record = RecordState::new($this->users());
		$ref = $record->getValueRef('id');

		$command = new DeleteCommand($this->users(), ['id' => $ref]);

		self::assertSame($ref, $command->getIdentity()['id']);
	}

	public function testCommandValueResolverResolvesInsertValues(): void
	{
		$record = RecordState::new($this->users(), ['id' => 10]);
		$command = new InsertCommand($this->users(), ['id' => $record->getValueRef('id')]);
		$resolver = new CommandValueResolver();

		self::assertTrue($resolver->resolve($command));
		self::assertSame(['id' => 10], $command->getValues());
		self::assertFalse($resolver->hasUnresolvedValueRefs($command));
	}

	public function testCommandValueResolverResolvesUpdateIdentityAndChanges(): void
	{
		$identityRecord = RecordState::new($this->users(), ['id' => 10]);
		$nameRecord = RecordState::new($this->users(), ['name' => 'Ada']);
		$command = new UpdateCommand($this->users(), [
			'id' => $identityRecord->getValueRef('id'),
		], [
			'name' => $nameRecord->getValueRef('name'),
		]);
		$resolver = new CommandValueResolver();

		self::assertTrue($resolver->resolve($command));
		self::assertSame(['id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'Ada'], $command->getChanges());
	}

	public function testCommandValueResolverResolvesDeleteIdentity(): void
	{
		$record = RecordState::new($this->users(), ['id' => 10]);
		$command = new DeleteCommand($this->users(), ['id' => $record->getValueRef('id')]);
		$resolver = new CommandValueResolver();

		self::assertTrue($resolver->resolve($command));
		self::assertSame(['id' => 10], $command->getIdentity());
	}

	public function testCommandValueResolverDetectsUnresolvedRefsInAllCommandSlots(): void
	{
		$record = RecordState::new($this->users());
		$resolver = new CommandValueResolver();

		self::assertTrue($resolver->hasUnresolvedValueRefs(new InsertCommand($this->users(), [
			'id' => $record->getValueRef('id'),
		])));
		self::assertTrue($resolver->hasUnresolvedValueRefs(new UpdateCommand($this->users(), [
			'id' => $record->getValueRef('id'),
		], [
			'name' => 'Ada',
		])));
		self::assertTrue($resolver->hasUnresolvedValueRefs(new UpdateCommand($this->users(), [
			'id' => 10,
		], [
			'name' => $record->getValueRef('name'),
		])));
		self::assertTrue($resolver->hasUnresolvedValueRefs(new DeleteCommand($this->users(), [
			'id' => $record->getValueRef('id'),
		])));
	}

	public function testCommandValueResolverAssertReadyThrowsUsefulMessage(): void
	{
		$record = RecordState::new($this->users());
		$command = new InsertCommand($this->users(), ['id' => $record->getValueRef('id')]);

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('Insert command');
		$this->expectExceptionMessage('values');
		$this->expectExceptionMessage("field 'id'");
		$this->expectExceptionMessage('users.id');

		(new CommandValueResolver())->assertReady($command);
	}

	public function testCommandValueResolverAssertReadyPassesAfterRefsResolve(): void
	{
		$record = RecordState::new($this->users());
		$command = new InsertCommand($this->users(), ['id' => $record->getValueRef('id')]);
		$record->setValue('id', 10);
		$resolver = new CommandValueResolver();

		$resolver->assertReady($command);

		self::assertSame(['id' => 10], $command->getValues());
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
}
