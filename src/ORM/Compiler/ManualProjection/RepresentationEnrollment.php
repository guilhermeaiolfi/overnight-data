<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\State\RepresentationState;

final class RepresentationEnrollment
{
	public function __construct(
		public readonly object $representation,
		public readonly RepresentationState $state,
	) {
	}
}
