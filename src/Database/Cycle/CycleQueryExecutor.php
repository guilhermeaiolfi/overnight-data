<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use ON\Data\Database\Exception\QueryExecutionException;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Database\QueryPartitionLimiter;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use Throwable;
use WeakMap;

final class CycleQueryExecutor implements QueryExecutorInterface, QueryPartitionLimiter
{
	private readonly CycleQueryTranslator $translator;

	private readonly CycleResultMapper $mapper;

	/**
	 * @var WeakMap<SelectQuery, CyclePartitionedLimit>
	 */
	private readonly WeakMap $partitionedLimits;

	public function __construct(
		private readonly DatabaseInterface $database,
		ConversionGateway $gateway,
	) {
		$this->partitionedLimits = new WeakMap();
		$this->translator = new CycleQueryTranslator($this->database, $gateway, $this->partitionedLimits);
		$this->mapper = new CycleResultMapper($gateway);
	}

	/**
	 * @param non-empty-list<string> $partitionFields
	 * @param non-empty-list<Sort> $orderBy
	 */
	public function applyPartitionedLimit(
		SelectQuery $query,
		QuerySourceInterface $source,
		array $partitionFields,
		array $orderBy,
		int $limit,
		int $offset,
		string $rowNumberAlias,
	): SelectQuery {
		$this->partitionedLimits[$query] = new CyclePartitionedLimit(
			$source,
			$partitionFields,
			$orderBy,
			$limit,
			$offset,
			$rowNumberAlias,
		);

		return $query;
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
			$translated = $this->translator->translate($query);
			$select = clone $translated->query();
			$currentLimit = $select->getLimit();
			$select->limit($currentLimit === null ? 1 : min($currentLimit, 1));
			$row = $select->fetchAll(StatementInterface::FETCH_ASSOC)[0] ?? null;

			return $row === null ? null : $this->mapper->map($row, $translated);
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	public function iterate(SelectQuery $query): iterable
	{
		try {
			$translated = $this->translator->translate($query);
			$statement = $translated->query()->run();

			return $this->iterateMapped($statement, $translated, $query);
		} catch (UnsupportedQueryException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw QueryExecutionException::forQuery($query, $exception);
		}
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	private function iterateMapped(
		StatementInterface $statement,
		CycleTranslatedQuery $translated,
		SelectQuery $query,
	): iterable {
		try {
			while (true) {
				try {
					$row = $statement->fetch(StatementInterface::FETCH_ASSOC);
					if ($row === false) {
						break;
					}

					if (! is_array($row)) {
						continue;
					}

					yield $this->mapper->map($row, $translated);
				} catch (UnsupportedQueryException $exception) {
					throw $exception;
				} catch (Throwable $exception) {
					throw QueryExecutionException::forQuery($query, $exception);
				}
			}
		} finally {
			$statement->close();
		}
	}
}
