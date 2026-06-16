<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

class HasManyRelation extends AbstractRelation
{
	public function getCardinality(): string
	{
		return 'many';
	}
}
