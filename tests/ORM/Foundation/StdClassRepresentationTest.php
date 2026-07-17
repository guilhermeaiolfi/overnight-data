<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class StdClassRepresentationTest extends TestCase
{
	public function testStdClassCanBeWritableWhenLineageExists(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($users, new StdClassUserExecutor());

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->name = 'Ada Lovelace';
		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame(['id' => 1], $command->getIdentity());
		self::assertSame(['name' => 'Ada Lovelace'], $command->getChanges());
	}

	public function testStdClassWithoutLineageIsOnlyAProjectionUnlessAttached(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('without a root RepresentationSchema');

		$session->sync(new stdClass());
	}
}

final class StdClassUserExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [['id' => 1, 'name' => 'Ada']];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return ['id' => 1, 'name' => 'Ada'];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}
