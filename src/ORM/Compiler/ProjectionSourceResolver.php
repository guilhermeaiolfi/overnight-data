<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

interface ProjectionSourceResolver
{
	public function resolve(object $source): ProjectionSourceTarget;
}
