<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class PersistenceSmokeTest extends TestCase
{
	public function testDocumentedNewRecordFlushMergesGeneratedIdAndPersistsRow(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE app_users (user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT)');

		$registry = new Registry();
		$users = $registry
			->collection('users')
			->table('app_users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->autoIncrement(true)->end()
			->field('name', 'string')->column('full_name')->end();

		$session = new Session(new CycleCommandExecutor($harness->cycleDatabase));

		/** @var RecordState $record */
		$record = RecordState::new($users, [
			'name' => 'Ada Lovelace',
		]);
		$session->getRecords()->add($record);

		$session->flush();

		$generatedId = $record->getValue('id');
		self::assertIsInt($generatedId);
		self::assertGreaterThan(0, $generatedId);
		self::assertTrue($record->isClean());
		self::assertSame($generatedId, $record->getValue('id'));

		$row = $harness->fetchRow('SELECT full_name FROM app_users WHERE user_id = ?', [$generatedId]);
		self::assertSame(['full_name' => 'Ada Lovelace'], $row);
	}
}
