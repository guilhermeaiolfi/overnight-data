<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use InvalidArgumentException;
use ON\Data\Database\DatabaseFamily;

/** @internal */
final class MySqlDialect extends AbstractDialect
{
	protected const OPEN_QUOTE = '`';
	protected const CLOSE_QUOTE = '`';
	protected const UNLIMITED = 'LIMIT 18446744073709551615';
	protected const DEFAULT_VALUES = '() VALUES ()';

	public function __construct(
		private readonly DatabaseFamily $family = DatabaseFamily::MySQL,
		private readonly bool $returning = false,
	) {
		if ($family !== DatabaseFamily::MySQL && $family !== DatabaseFamily::MariaDB) {
			throw new InvalidArgumentException('MySqlDialect requires the MySQL or MariaDB family.');
		}
	}

	public function family(): DatabaseFamily
	{
		return $this->family;
	}

	public function compileConcat(array $sqlArguments): string
	{
		return 'CONCAT(' . implode(', ', $sqlArguments) . ')';
	}

	public function supportsInsertReturning(): bool
	{
		return $this->family === DatabaseFamily::MariaDB && $this->returning;
	}
}
