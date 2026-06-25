<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Definition\Collection\CollectionInterface;

/**
 * @param null|callable(SelectQuery): mixed $build
 */
function query(CollectionInterface $source, ?callable $build = null): SelectQuery
{
	$query = new SelectQuery($source);

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
