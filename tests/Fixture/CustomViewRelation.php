<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Definition\Relation\AbstractRelation;

class CustomViewRelation extends AbstractRelation
{
	public function getCardinality(): string
	{
		return 'many';
	}
}
