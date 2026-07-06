<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

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
