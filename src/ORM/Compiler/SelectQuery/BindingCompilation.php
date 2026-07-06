<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\ORM\State\RepresentationBinding;

final class BindingCompilation
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
