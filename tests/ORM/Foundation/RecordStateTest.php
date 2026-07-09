<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Session;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class RecordStateTest extends TestCase
{
	public function testFlushWritesDirtyRecordStatesNotArbitraryObjectState(): void
	{
		$users = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'A1']);
		$record->setValue('name', 'A2');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$session->getRecords()->add($record);

		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame(['id' => 10], $command->getIdentity());
		self::assertSame(['name' => 'A2'], $command->getChanges());
	}

	public function testRegistryRemainsOrmAgnostic(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Definition/Registry.php');

		foreach ([
			'ON\\Data\\ORM',
			'RecordState',
			'UnitOfWork',
			'IdentityMap',
			'LazyLoading',
			'RepresentationState',
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $contents, $forbidden);
		}
	}
}
