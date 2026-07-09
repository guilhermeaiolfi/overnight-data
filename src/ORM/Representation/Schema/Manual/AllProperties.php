<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

/**
 * Expands to all collection fields on a ManualRepresentationSourceInterface for Builder::properties().
 *
 * Exists as sugar for $target->all() without listing every field explicitly.
 */
final class AllProperties
{
	public function __construct(
		private ManualRepresentationSourceInterface $source,
	) {
	}

	public function getSource(): ManualRepresentationSourceInterface
	{
		return $this->source;
	}
}
