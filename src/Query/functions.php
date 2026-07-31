<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;

/**
 * @param null|callable(SelectQuery): mixed $build
 */
function query(CollectionInterface|SelectQuery|DerivedSelectQuery $source, ?callable $build = null): SelectQuery
{
	$query = match (true) {
		$source instanceof SelectQuery && $build !== null => $source,
		$source instanceof DerivedSelectQuery => new SelectQuery($source),
		$source instanceof SelectQuery => throw new InvalidArgumentException(
			'SelectQuery sources must be wrapped with as() before query($source).',
		),
		default => new SelectQuery($source),
	};

	if ($build !== null) {
		$build($query);
	}

	return $query;
}

function x(): ExpressionFactory
{
	static $factory;

	return $factory ??= new ExpressionFactory();
}
