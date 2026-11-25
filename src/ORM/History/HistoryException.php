<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use ON\Data\ORM\Exception\OrmException;

class HistoryException extends OrmException
{
	public static function activeTransaction(): self
	{
		return new self('Flush history requires an outermost transaction; the executor already has an active transaction.');
	}
}
