<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityMap;
use ON\Data\ORM\State\RepresentationBinding;

final class SelectQueryBindingCompilation
{
	public function __construct(
		private RepresentationBinding $binding,
		private ProjectionIdentityMap $projectionIdentities,
	) {
	}

	public function getBinding(): RepresentationBinding
	{
		return $this->binding;
	}

	public function getProjectionIdentities(): ProjectionIdentityMap
	{
		return $this->projectionIdentities;
	}
}
