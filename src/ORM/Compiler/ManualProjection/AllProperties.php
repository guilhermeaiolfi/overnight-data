<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Expands to all collection fields on a PropertySource for Builder::properties().
 *
 * Exists as sugar for $target->all() without listing every field explicitly.
 */
final class AllProperties
{
	public function __construct(
		private PropertySource $source,
	) {
	}

	public function getSource(): PropertySource
	{
		return $this->source;
	}
}
