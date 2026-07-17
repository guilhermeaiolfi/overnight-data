<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;

/**
 * Input for {@see RepresentationAdoptionEngine::attach()}.
 *
 * Required: schema + policy.
 * Optional only when needed: identities, sourceRow (query lookup bag), intent (flat ops).
 */
final class RepresentationAdoptionContext
{
	/**
	 * @param array<string, mixed>|null $sourceRow
	 */
	public function __construct(
		private RepresentationSchema $schema,
		private AdoptionPolicy $policy,
		private ?RepresentationSourceIdentities $identities = null,
		private ?array $sourceRow = null,
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

	public function getIdentities(): ?RepresentationSourceIdentities
	{
		return $this->identities;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSourceRow(): ?array
	{
		return $this->sourceRow;
	}

	public function getIntent(): ?RepresentationIntent
	{
		return $this->intent;
	}

	/**
	 * @return list<RepresentationSource>
	 */
	public function getSources(): array
	{
		return RepresentationSource::fromRepresentationSchema($this->schema);
	}
}
