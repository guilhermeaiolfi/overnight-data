<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use ON\Data\Database\Exception\QueryExecutionException;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Query\SelectQuery;
use Throwable;

final class CycleQueryExecutor implements QueryExecutorInterface
{
	private readonly CycleQueryTranslator $translator;

	private readonly CycleResultMapper $mapper;

	public function __construct(
		private readonly DatabaseInterface $database,
		ConversionGateway $gateway,
	) {
		$this->translator = new CycleQueryTranslator($this->database, $gateway);
		$this->mapper = new CycleResultMapper($gateway);
	}

	public function fetchAll(SelectQuery $query): array
	{
		try {
			$plan = $this->translator->translate($query);
			$rows = $plan->query()->fetchAll(StatementInterface::FETCH_ASSOC);

			return array_map(
				fn (array $row): array => $this->mapper->map($row, $plan),
				$rows,
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
			$plan = $this->translator->translate($query);
			$select = clone $plan->query();
			$select->limit(1);
			$row = $select->fetchAll(StatementInterface::FETCH_ASSOC)[0] ?? null;

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
			$plan = $this->translator->translate($query);
			$statement = $plan->query()->run();

			return $this->iterateMapped($statement, $plan);
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	private function iterateMapped(StatementInterface $statement, CycleQueryPlan $plan): iterable
	{
		try {
			while (($row = $statement->fetch(StatementInterface::FETCH_ASSOC)) !== false) {
				if (! is_array($row)) {
					continue;
				}

				yield $this->mapper->map($row, $plan);
			}
		} finally {
			$statement->close();
		}
	}
}
