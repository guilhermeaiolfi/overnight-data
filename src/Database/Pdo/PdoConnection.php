<?php

declare(strict_types=1);

namespace ON\Data\Database\Pdo;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\TransactionStateAwareInterface;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoConnection implements ConnectionInterface, TransactionStateAwareInterface
{
	private readonly DatabaseFamily $family;

	private readonly ?string $serverVersion;

	/** @var array<int, mixed> */
	private readonly array $statementOptions;

	public function __construct(
		private readonly PDO $pdo,
		private readonly string $tablePrefix = '',
		private readonly ?bool $insertReturning = null,
	) {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
		$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

		if (in_array($driver, ['mysql', 'pgsql'], true)) {
			$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}
		$this->statementOptions = $driver === 'sqlsrv' ? [PDO::ATTR_EMULATE_PREPARES => false] : [];

		try {
			$version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
		} catch (PDOException) {
			$version = null;
		}

		$this->serverVersion = is_string($version) ? $version : null;
		$this->family = $driver === 'mysql' && stripos($this->serverVersion ?? '', 'MariaDB') !== false
			? DatabaseFamily::MariaDB
			: DatabaseFamily::fromDriverType($driver);
	}

	public static function connect(ConnectionConfig $config): self
	{
		return new self(
			new PDO($config->dsn, $config->username, $config->password, $config->options),
			$config->tablePrefix,
			$config->insertReturning,
		);
	}

	public function family(): DatabaseFamily
	{
		return $this->family;
	}

	public function tablePrefix(): string
	{
		return $this->tablePrefix;
	}

	public function nativeDriver(): PDO
	{
		return $this->pdo;
	}

	/** @internal */
	public function serverVersion(): ?string
	{
		return $this->serverVersion;
	}

	/** @internal */
	public function insertReturningOverride(): ?bool
	{
		return $this->insertReturning;
	}

	public function fetchAll(string $sql, array $params = []): array
	{
		$statement = $this->prepareAndExecute($sql, $params);

		try {
			return $statement->fetchAll(PDO::FETCH_ASSOC);
		} finally {
			$statement->closeCursor();
		}
	}

	public function fetchOne(string $sql, array $params = []): ?array
	{
		foreach ($this->iterate($sql, $params) as $row) {
			return $row;
		}

		return null;
	}

	public function iterate(string $sql, array $params = []): iterable
	{
		$statement = $this->prepareAndExecute($sql, $params);

		try {
			while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
				yield $row;
			}
		} finally {
			$statement->closeCursor();
		}
	}

	public function execute(string $sql, array $params = []): int
	{
		$statement = $this->prepareAndExecute($sql, $params);

		try {
			return $statement->rowCount();
		} finally {
			$statement->closeCursor();
		}
	}

	public function lastInsertId(?string $sequence = null): string|false
	{
		return $this->pdo->lastInsertId($sequence);
	}

	public function isInTransaction(): bool
	{
		return $this->pdo->inTransaction();
	}

	public function transaction(callable $callback): mixed
	{
		if ($this->pdo->inTransaction()) {
			return $this->withinSavepoint($callback);
		}

		$this->pdo->beginTransaction();

		try {
			$result = $callback();
			$this->pdo->commit();

			return $result;
		} catch (Throwable $error) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}

			throw $error;
		}
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private function withinSavepoint(callable $callback): mixed
	{
		$name = 'ondata_' . bin2hex(random_bytes(10));
		$sqlServer = $this->family === DatabaseFamily::SqlServer;
		$this->pdo->exec(($sqlServer ? 'SAVE TRANSACTION ' : 'SAVEPOINT ') . $name);

		try {
			$result = $callback();
		} catch (Throwable $error) {
			if ($this->pdo->inTransaction()) {
				try {
					$this->pdo->exec(($sqlServer ? 'ROLLBACK TRANSACTION ' : 'ROLLBACK TO SAVEPOINT ') . $name);
					if (! $sqlServer) {
						$this->pdo->exec('RELEASE SAVEPOINT ' . $name);
					}
				} catch (Throwable $cleanup) {
					throw new RuntimeException('Savepoint rollback failed: ' . $cleanup->getMessage(), previous: $error);
				}
			}

			throw $error;
		}

		if (! $sqlServer) {
			$this->pdo->exec('RELEASE SAVEPOINT ' . $name);
		}

		return $result;
	}

	/** @param list<mixed> $params */
	private function prepareAndExecute(string $sql, array $params): PDOStatement
	{
		$statement = $this->pdo->prepare($sql, $this->statementOptions);
		foreach ($params as $index => $value) {
			$value = match (true) {
				$value instanceof BackedEnum => $value->value,
				$value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
					->setTimezone(new DateTimeZone('UTC'))
					->format('Y-m-d H:i:s'),
				default => $value,
			};
			$type = match (true) {
				$value === null => PDO::PARAM_NULL,
				is_bool($value) => PDO::PARAM_BOOL,
				is_int($value) => PDO::PARAM_INT,
				is_string($value), is_float($value) => PDO::PARAM_STR,
				is_resource($value) => PDO::PARAM_LOB,
				default => throw new InvalidArgumentException('Unsupported SQL parameter type: ' . get_debug_type($value)),
			};
			$statement->bindValue($index + 1, $value, $type);
		}

		$statement->execute();

		return $statement;
	}
}
