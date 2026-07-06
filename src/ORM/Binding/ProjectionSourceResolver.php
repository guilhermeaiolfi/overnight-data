<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

interface ProjectionSourceResolver
{
	public function resolve(object $source): ProjectionSourceTarget;
}
