<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Schema\RepresentationSchema;

/**
 * Input for {@see RepresentationAdoptionEngine::attach()}.
 *
 * Required: schema + policy.
 * Optional identities and flat operations come from the staged intent.
 */
final class RepresentationAdoptionContext
{
	public function __construct(
		private RepresentationSchema $schema,
		private AdoptionPolicy $policy,
		private ?StaticSourceIdentities $identities = null,
		private ?RepresentationIntent $intent = null,
	) {
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	public function getPolicy(): AdoptionPolicy
	{
		return $this->policy;
	}

	public function getIdentities(): ?StaticSourceIdentities
	{
		return $this->identities;
	}

	public function getIntent(): ?RepresentationIntent
	{
		return $this->intent;
	}
}
