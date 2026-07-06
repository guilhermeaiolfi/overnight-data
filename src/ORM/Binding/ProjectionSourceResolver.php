<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\Query\QuerySourceInterface;

interface ProjectionSourceResolver
{
	public function resolve(QuerySourceInterface $source): ProjectionSourceTarget;
}
