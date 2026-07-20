<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\ReturningInterface;
use Cycle\Database\StatementInterface;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\InsertCommand;
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

	public function testInsertReturningSingleColumnUsesFetchColumnAndRowCount(): void
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
		$statement->expects(self::once())->method('fetchColumn')->willReturn('15');
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

	public function testInsertReturningReportsDriverRowCount(): void
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
		$statement->method('fetchColumn')->willReturn('1');
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

		self::assertSame(0, $result->getAffectedRows());
		self::assertSame(['id' => 1], $result->getGeneratedValues());
	}
}
