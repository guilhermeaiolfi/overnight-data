<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\ORM\Query\ProjectionIdentityMap;
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
