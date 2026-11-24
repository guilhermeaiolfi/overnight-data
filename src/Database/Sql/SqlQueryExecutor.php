<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\Exception\QueryExecutionException;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Database\Sql\Dialect\DialectFactory;
use ON\Data\Database\Sql\Dialect\DialectInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Query\SelectQuery;
use Throwable;

final class SqlQueryExecutor implements QueryExecutorInterface
{
	private readonly SqlQueryCompiler $compiler;

	private readonly SqlResultMapper $mapper;

	public function __construct(
		private readonly ConnectionInterface $connection,
		ConversionGateway $gateway,
		?DialectInterface $dialect = null,
	) {
		$this->compiler = new SqlQueryCompiler(
			$dialect ?? DialectFactory::forConnection($connection),
			$connection,
			$gateway,
			$connection->tablePrefix(),
		);
		$this->mapper = new SqlResultMapper($gateway);
	}

	public function fetchAll(SelectQuery $query): array
	{
		try {
			$plan = $this->compiler->compile($query);

			return array_map(
				fn (array $row): array => $this->mapper->map($row, $plan),
				$this->connection->fetchAll($plan->sql, $plan->params),
			);
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		try {
			$plan = $this->compiler->compile($query, maxRows: 1);
			$row = $this->connection->fetchOne($plan->sql, $plan->params);

			return $row === null ? null : $this->mapper->map($row, $plan);
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	public function iterate(SelectQuery $query): iterable
	{
		try {
			return $this->iterateMapped($query, $this->compiler->compile($query));
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	/** @return iterable<array<string, mixed>> */
	private function iterateMapped(SelectQuery $query, CompiledQuery $plan): iterable
	{
		try {
			foreach ($this->connection->iterate($plan->sql, $plan->params) as $row) {
				yield $this->mapper->map($row, $plan);
			}
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}
}
