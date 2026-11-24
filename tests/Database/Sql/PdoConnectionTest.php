<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Sql;

use DateTimeImmutable;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Pdo\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('pdo_sqlite')]
final class PdoConnectionTest extends TestCase
{
	public function testBindsParametersWithTheirStorageTypes(): void
	{
		$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$connection = new PdoConnection($pdo);
		$blob = fopen('php://memory', 'w+b');
		self::assertIsResource($blob);
		fwrite($blob, "a\0b");
		rewind($blob);

		self::assertSame(DatabaseFamily::Sqlite, $connection->family());
		self::assertSame($pdo, $connection->nativeDriver());
		self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
		self::assertSame(
			['n' => 'null', 'b' => 'integer', 'i' => 'integer', 's' => 'text', 'lob' => 'blob'],
			$connection->fetchOne(
				'SELECT typeof(?) AS n, typeof(?) AS b, typeof(?) AS i, typeof(?) AS s, typeof(?) AS lob',
				[null, false, 7, '007', $blob],
			),
		);
		fclose($blob);
	}

	public function testIterationStartsOnDemandAndReleasesDiscardedCursor(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		$rows = $connection->iterate('SELECT n FROM numbers ORDER BY n');
		$connection->execute('CREATE TABLE numbers (n INTEGER)');
		$connection->execute('INSERT INTO numbers VALUES (1), (2)');

		foreach ($rows as $row) {
			self::assertSame(['n' => 1], $row);

			break;
		}
		unset($rows);

		$connection->execute('DROP TABLE numbers');
		self::assertSame([], $connection->fetchAll("SELECT name FROM sqlite_master WHERE name = 'numbers'"));
	}

	public function testRawDateTimeParametersUseUtc(): void
	{
		$timezone = date_default_timezone_get();
		date_default_timezone_set('America/Sao_Paulo');

		try {
			$connection = new PdoConnection(new PDO('sqlite::memory:'));
			$timestamp = new DateTimeImmutable('2026-11-24 00:30:00+00:00');

			self::assertSame(
				['ts' => '2026-11-24 00:30:00'],
				$connection->fetchOne('SELECT ? AS ts', [$timestamp]),
			);
		} finally {
			date_default_timezone_set($timezone);
		}
	}

	public function testFailureInsideCallerTransactionRollsBackOnlyInnerWork(): void
	{
		$pdo = new PDO('sqlite::memory:');
		$connection = new PdoConnection($pdo);
		$connection->execute('CREATE TABLE numbers (n INTEGER)');
		$pdo->beginTransaction();
		$connection->execute('INSERT INTO numbers VALUES (?)', [1]);

		$failure = new RuntimeException('fail nested write');

		try {
			$connection->transaction(function () use ($connection, $failure): void {
				$connection->execute('INSERT INTO numbers VALUES (?)', [2]);

				throw $failure;
			});
			self::fail('Expected the nested transaction to throw.');
		} catch (RuntimeException $error) {
			self::assertSame($failure, $error);
		}

		self::assertTrue($pdo->inTransaction());
		self::assertSame('saved', $connection->transaction(function () use ($connection): string {
			$connection->execute('INSERT INTO numbers VALUES (?)', [3]);

			return 'saved';
		}));
		$pdo->commit();
		self::assertSame([['n' => 1], ['n' => 3]], $connection->fetchAll('SELECT n FROM numbers ORDER BY n'));
	}
}
