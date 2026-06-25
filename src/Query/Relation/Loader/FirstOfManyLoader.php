<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;

final class FirstOfManyLoader extends AbstractLoader
{
	public function join(RelationRef $relation): QuerySourceInterface
	{
		throw RelationLoaderException::firstOfManyJoinNotImplemented($relation);
	}
}
