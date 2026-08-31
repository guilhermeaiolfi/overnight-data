<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\ReturningInterface;
use Cycle\Database\Query\SelectQuery;
use Cycle\Database\Query\UpdateQuery;
use Cycle\Database\StatementInterface;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use PHPUnit\Framework\TestCase;

final class CycleCommandExecutorGeneratedValuesTest extends TestCase
{
	public function testInsertPassesGeneratorSequenceToLastInsertId(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class, 'users_id_seq')->end()
			->field('name', 'string')->end();

		$insert = new class ('users') extends InsertQuery {
			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (name) VALUES (?)';
			}
		};

		$driver = $this->createMock(DriverInterface::class);
		$driver->expects(self::once())->method('execute')->willReturn(1);
		$driver->expects(self::never())->method('query');
		$driver->expects(self::once())
			->method('lastInsertID')
			->with('users_id_seq')
			->willReturn('42');

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'name' => 'Ada',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 42], $result->getGeneratedValues());
	}

	public function testInsertUsesReturningForPendingDatabaseGeneratedFields(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->generator(DatabaseGenerator::class)->end()
			->field('token', 'string')->column('api_token')->generator(DatabaseGenerator::class)->end()
			->field('name', 'string')->end();

		$insert = new class ('users') extends InsertQuery implements ReturningInterface {
			/** @var list<string> */
			public array $returningColumns = [];

			public function returning(string|FragmentInterface ...$columns): self
			{
				$this->returningColumns = array_values(array_map(
					static fn (string|FragmentInterface $column): string => (string) $column,
					$columns,
				));

				return $this;
			}

			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (name) VALUES (?) RETURNING user_id, api_token';
			}
		};

		$statement = $this->createMock(StatementInterface::class);
		$statement->expects(self::once())
			->method('fetch')
			->with(StatementInterface::FETCH_ASSOC)
			->willReturn([
				'user_id' => '9',
				'api_token' => 'tok-1',
			]);
		$statement->expects(self::once())->method('rowCount')->willReturn(1);
		$statement->expects(self::once())->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->expects(self::once())->method('query')->willReturn($statement);
		$driver->expects(self::never())->method('execute');
		$driver->expects(self::never())->method('lastInsertID');

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'name' => 'Ada',
		]));

		self::assertSame(['user_id', 'api_token'], $insert->returningColumns);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame([
			'id' => 9,
			'token' => 'tok-1',
		], $result->getGeneratedValues());
	}

	public function testInsertReturningSingleColumnUsesAssocRowAndRowCount(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->column('user_id')->generator(DatabaseGenerator::class)->end()
			->field('name', 'string')->end();

		$insert = new class ('users') extends InsertQuery implements ReturningInterface {
			public function returning(string|FragmentInterface ...$columns): self
			{
				return $this;
			}

			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (name) VALUES (?) RETURNING user_id';
			}
		};

		$statement = $this->createMock(StatementInterface::class);
		$statement->expects(self::once())
			->method('fetch')
			->with(StatementInterface::FETCH_ASSOC)
			->willReturn(['user_id' => '15']);
		$statement->expects(self::never())->method('fetchColumn');
		$statement->expects(self::once())->method('rowCount')->willReturn(1);
		$statement->expects(self::once())->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->method('query')->willReturn($statement);

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'name' => 'Ada',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 15], $result->getGeneratedValues());
	}

	public function testInsertReturningTreatsRecoveredRowAsOneAffectedWhenDriverReportsZero(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class)->end()
			->field('name', 'string')->end();

		$insert = new class ('users') extends InsertQuery implements ReturningInterface {
			public function returning(string|FragmentInterface ...$columns): self
			{
				return $this;
			}

			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (name) VALUES (?) RETURNING id';
			}
		};

		$statement = $this->createMock(StatementInterface::class);
		$statement->method('fetch')->willReturn(['id' => '1']);
		$statement->method('rowCount')->willReturn(0);
		$statement->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->method('query')->willReturn($statement);

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'name' => 'Ada',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['id' => 1], $result->getGeneratedValues());
	}

	public function testInsertUsesSqliteChangesWhenExecuteReportsZeroAffectedRows(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$insert = new class ('users') extends InsertQuery {
			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (id, name) VALUES (?, ?)';
			}
		};

		$statement = $this->createMock(StatementInterface::class);
		$statement->expects(self::once())
			->method('fetch')
			->with(StatementInterface::FETCH_ASSOC)
			->willReturn(['affected' => 1]);
		$statement->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->method('getType')->willReturn('SQLite');
		$driver->expects(self::once())->method('execute')->willReturn(0);
		$driver->expects(self::once())->method('query')->with('SELECT CHANGES() AS affected')->willReturn($statement);
		$driver->expects(self::never())->method('lastInsertID');

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'id' => 10,
			'name' => 'Assigned',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame([], $result->getGeneratedValues());
	}

	public function testInsertReturningFallsBackToSqliteChangesWhenRecoveredRowDoesNotMap(): void
	{
		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();

		$insert = new class ('users') extends InsertQuery implements ReturningInterface {
			/** @var list<string> */
			public array $returningColumns = [];

			public function returning(string|FragmentInterface ...$columns): self
			{
				$this->returningColumns = array_values(array_map(
					static fn (string|FragmentInterface $column): string => (string) $column,
					$columns,
				));

				return $this;
			}

			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'INSERT INTO users (id, name) VALUES (?, ?) RETURNING email';
			}
		};

		$returningStatement = $this->createMock(StatementInterface::class);
		$returningStatement->method('fetch')->willReturn(['"email"' => 'email']);
		$returningStatement->method('rowCount')->willReturn(0);
		$returningStatement->method('close');

		$changesStatement = $this->createMock(StatementInterface::class);
		$changesStatement->expects(self::once())
			->method('fetch')
			->with(StatementInterface::FETCH_ASSOC)
			->willReturn(['affected' => 1]);
		$changesStatement->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->method('getType')->willReturn('SQLite');
		$driver->expects(self::never())->method('execute');
		$driver->expects(self::exactly(2))
			->method('query')
			->willReturnOnConsecutiveCalls($returningStatement, $changesStatement);

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($users, [
			'id' => 10,
			'name' => 'Assigned',
		]));

		self::assertSame(['email'], $insert->returningColumns);
		self::assertSame(1, $result->getAffectedRows());
		self::assertSame([], $result->getGeneratedValues());
	}

	public function testInsertUsesReturningForOmittedSqlDefaultColumns(): void
	{
		$notes = (new Registry())
			->collection('notes')
			->table('notes')
			->primaryKey('id')
			->field('id', 'int')->column('note_id')->generator(DatabaseGenerator::class)->end()
			->field('title', 'string')->end()
			->field('status', 'string')->column('note_status')->end();

		$insert = new class ('notes') extends InsertQuery implements ReturningInterface {
			/** @var list<string> */
			public array $returningColumns = [];

			public function returning(string|FragmentInterface ...$columns): self
			{
				$this->returningColumns = array_values(array_map(
					static fn (string|FragmentInterface $column): string => (string) $column,
					$columns,
				));

				return $this;
			}

			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'insert notes returning';
			}
		};

		$statement = $this->createMock(StatementInterface::class);
		$statement->expects(self::once())
			->method('fetch')
			->with(StatementInterface::FETCH_ASSOC)
			->willReturn([
				'note_id' => '4',
				'note_status' => 'draft',
			]);
		$statement->method('rowCount')->willReturn(1);
		$statement->method('close');

		$driver = $this->createMock(DriverInterface::class);
		$driver->expects(self::once())->method('query')->willReturn($statement);
		$driver->expects(self::never())->method('execute');

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($notes, [
			'title' => 'Hello',
		]));

		self::assertSame(['note_id', 'note_status'], $insert->returningColumns);
		self::assertSame([
			'id' => 4,
			'status' => 'draft',
		], $result->getGeneratedValues());
	}

	public function testInsertSelectsOmittedColumnsAfterLastInsertIdWhenReturningIsUnavailable(): void
	{
		$notes = (new Registry())
			->collection('notes')
			->table('notes')
			->primaryKey('id')
			->field('id', 'int')->generator(DatabaseGenerator::class)->end()
			->field('title', 'string')->end()
			->field('status', 'string')->column('note_status')->end();

		$insert = new class ('notes') extends InsertQuery {
			public function sqlStatement(?QueryParameters $parameters = null): string
			{
				return 'insert notes';
			}
		};

		$select = $this->createMock(SelectQuery::class);
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('limit')->willReturnSelf();
		$select->method('fetchAll')->willReturn([['note_status' => 'draft']]);

		$driver = $this->createMock(DriverInterface::class);
		$driver->expects(self::once())->method('execute')->willReturn(1);
		$driver->expects(self::once())->method('lastInsertID')->willReturn('7');
		$driver->expects(self::never())->method('query');

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('insert')->willReturn($insert);
		$database->method('getDriver')->willReturn($driver);
		$database->expects(self::once())->method('select')->with(['note_status'])->willReturn($select);

		$result = (new CycleCommandExecutor($database))->execute(new InsertCommand($notes, [
			'title' => 'Hello',
		]));

		self::assertSame([
			'id' => 7,
			'status' => 'draft',
		], $result->getGeneratedValues());
	}

	public function testUpdateSelectsDatabaseGeneratedColumnsAfterWrite(): void
	{
		$notes = (new Registry())
			->collection('notes')
			->table('notes')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('stamp', 'string')->column('note_stamp')->generator(DatabaseGenerator::class, null, When::UPDATE)->end();

		$update = $this->createMock(UpdateQuery::class);
		$update->method('values')->willReturnSelf();
		$update->method('where')->willReturnSelf();
		$update->expects(self::once())->method('run')->willReturn(1);

		$select = $this->createMock(SelectQuery::class);
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('limit')->willReturnSelf();
		$select->method('fetchAll')->willReturn([['note_stamp' => 'touched']]);

		$database = $this->createMock(DatabaseInterface::class);
		$database->method('update')->willReturn($update);
		$database->expects(self::once())->method('select')->with(['note_stamp'])->willReturn($select);

		$result = (new CycleCommandExecutor($database))->execute(new UpdateCommand($notes, [
			'id' => 3,
		], [
			'title' => 'Updated',
		]));

		self::assertSame(1, $result->getAffectedRows());
		self::assertSame(['stamp' => 'touched'], $result->getGeneratedValues());
	}
}
