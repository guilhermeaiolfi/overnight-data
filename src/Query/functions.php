<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Definition\DefinitionInterface;

/**
 * @param null|callable(SelectQuery): mixed $build
 */
function query(DefinitionInterface $source, ?callable $build = null): SelectQuery
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
