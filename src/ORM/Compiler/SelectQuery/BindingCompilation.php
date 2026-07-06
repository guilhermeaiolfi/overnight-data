<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Query compilation result pairing the public RepresentationBinding with the
 * ProjectionIdentityMap needed to adopt flat mutable query rows.
 *
 * Exists because mutable SelectQuery export must pass identity metadata to
 * ProjectionRepresentationAdopter separately from the user-visible binding.
 */
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
