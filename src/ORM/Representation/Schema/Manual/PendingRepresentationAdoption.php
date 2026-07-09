<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\ORM\Representation\State\RepresentationState;
final class PendingRepresentationAdoption
{
	public function __construct(
		public readonly object $representation,
		public readonly RepresentationState $state,
	) {
	}
}
