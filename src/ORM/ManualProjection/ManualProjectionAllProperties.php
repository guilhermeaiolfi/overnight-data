<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

final class ManualProjectionAllProperties
{
	public function __construct(
		private ManualProjectionPropertySource $source,
	) {
	}

	public function getSource(): ManualProjectionPropertySource
	{
		return $this->source;
	}
}
