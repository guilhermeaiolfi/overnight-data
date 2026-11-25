<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Sql;

use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\Sql\Dialect\SqlServerDialect;
use ON\Data\Database\Sql\SqlCommandExecutor;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\InsertCommand;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class SqlCommandExecutorGeneratedValuesTest extends TestCase
{
	public function testLastInsertIdReceivesSequenceAndSuppliesKeyToReadback(): void
	{
		$connection = $this->createMock(ConnectionInterface::class);
		$connection->method('family')->willReturn(DatabaseFamily::MySQL);
		$connection->method('tablePrefix')->willReturn('app_');
		$connection->expects(self::once())->method('execute')
			->with('INSERT INTO `app_notes` (`title`) VALUES (?)', ['Hello'])
			->willReturn(1);
		$connection->expects(self::once())->method('lastInsertId')->with('notes_id_seq')->willReturn('7');
		$connection->expects(self::once())->method('fetchOne')
			->with('SELECT `note_status` FROM `app_notes` WHERE `id` = ? LIMIT ?', [7, 1])
			->willReturn(['note_status' => 'draft']);

		$result = (new SqlCommandExecutor($connection))->execute(new InsertCommand($this->notes(), ['title' => 'Hello']));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 7, 'status' => 'draft'], $result->getGeneratedValues());
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $generated
	 */
	#[DataProvider('returningRows')]
	public function testReturningRowDistinguishesNullAndMissingColumns(array $row, array $generated): void
	{
		$connection = $this->createMock(ConnectionInterface::class);
		$connection->method('family')->willReturn(DatabaseFamily::PostgreSQL);
		$connection->expects(self::once())->method('fetchOne')
			->with('INSERT INTO "notes" ("id", "title") VALUES (?, ?) RETURNING "note_status"', [7, 'Hello'])
			->willReturn($row);

		$result = (new SqlCommandExecutor($connection))->execute(new InsertCommand($this->notes(), ['id' => 7, 'title' => 'Hello']));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame($generated, $result->getGeneratedValues());
	}

	/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
	public static function returningRows(): array
	{
		return [
			'exact null' => [['note_status' => null], ['status' => null]],
			'case-folded null' => [['NOTE_STATUS' => null], ['status' => null]],
			'missing column' => [['other_column' => null], []],
		];
	}

	public function testSqliteChangesFallbackWhenDriverReportsZero(): void
	{
		$connection = $this->createMock(ConnectionInterface::class);
		$connection->method('family')->willReturn(DatabaseFamily::Sqlite);
		$connection->expects(self::once())->method('execute')
			->with('INSERT INTO "notes" ("id", "title", "note_status") VALUES (?, ?, ?)', [7, 'Hello', 'draft'])
			->willReturn(0);
		$connection->expects(self::once())->method('fetchOne')->with('SELECT CHANGES() AS affected')
			->willReturn(['affected' => 1]);

		$result = (new SqlCommandExecutor($connection))->execute(new InsertCommand($this->notes(), [
			'id' => 7,
			'title' => 'Hello',
			'status' => 'draft',
		]));

		self::assertSame(1, $result->getAffectedRows());
	}

	public function testSqlServerWithReturningDisabledRejectsUnsafeGeneratedKeyRecovery(): void
	{
		$connection = $this->createMock(ConnectionInterface::class);
		$connection->expects(self::never())->method('execute');
		$connection->expects(self::never())->method('lastInsertId');
		$executor = new SqlCommandExecutor($connection, dialect: new SqlServerDialect(returning: false));

		$this->expectException(InvalidCommandException::class);
		$this->expectExceptionMessage('requires OUTPUT to recover a generated primary key safely');
		$executor->execute(new InsertCommand($this->notes(), ['title' => 'Hello']));
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFallbackReadsSqliteDefaultsWhenGeneratedKeyIsZero(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'), insertReturning: false);
		$connection->execute("CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT, note_status TEXT DEFAULT '0007')");
		$connection->execute('INSERT INTO notes (id, title) VALUES (?, ?)', [-1, 'Previous']);

		$result = (new SqlCommandExecutor($connection))->execute(new InsertCommand($this->notes(), ['title' => 'Hello']));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 0, 'status' => '0007'], $result->getGeneratedValues());
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFallbackWithoutIdentityKeepsSuccessfulInsert(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'), insertReturning: false);
		$connection->execute("CREATE TABLE notes (title TEXT, note_status TEXT DEFAULT 'draft')");
		$notes = (new Registry())->collection('notes')
			->field('title', 'string')->end()
			->field('status', 'string')->column('note_status')->end();

		$result = (new SqlCommandExecutor($connection))->execute(new InsertCommand($notes, ['title' => 'Hello']));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame([], $result->getGeneratedValues());
		self::assertSame(['note_status' => 'draft'], $connection->fetchOne('SELECT note_status FROM notes'));
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testSkippedInsertDoesNotRecoverAPreviousKey(): void
	{
		foreach ([false, true] as $returning) {
			$connection = new PdoConnection(new PDO('sqlite::memory:'), insertReturning: $returning);
			$connection->execute("CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT, note_status TEXT DEFAULT 'draft')");
			$connection->execute("CREATE TRIGGER skip_note BEFORE INSERT ON notes WHEN NEW.title = 'Skip' BEGIN SELECT RAISE(IGNORE); END");
			$executor = new SqlCommandExecutor($connection);
			$notes = $this->notes();

			self::assertSame(1, $executor->execute(new InsertCommand($notes, ['title' => 'Keep']))->getAffectedRows());
			$skipped = $executor->execute(new InsertCommand($notes, ['title' => 'Skip']));

			self::assertSame(0, $skipped->getAffectedRows());
			self::assertSame([], $skipped->getGeneratedValues());
		}
	}

	private function notes(): CollectionInterface
	{
		return (new Registry())->collection('notes')->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class, 'notes_id_seq')->end()
			->field('title', 'string')->end()
			->field('status', 'string')->column('note_status')->end();
	}
}
