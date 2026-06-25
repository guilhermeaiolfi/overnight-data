<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\FieldRef;

interface QuerySourceInterface
{
	public function getQuery(): SelectQuery;

	public function getCollection(): CollectionInterface;

	/**
	 * @return list<string>
	 */
	public function getPath(): array;

	public function field(string $name): FieldRef;
}
