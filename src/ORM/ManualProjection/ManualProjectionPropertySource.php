<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\State\RecordState;

interface ManualProjectionPropertySource
{
	public function getTargetRecord(): RecordState;
}
