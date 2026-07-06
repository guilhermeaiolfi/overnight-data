<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

final class ExistingIntent
{
	public function __construct(
		private object $representation,
	) {
	}

	public function getRepresentation(): object
	{
		return $this->representation;
	}
}
