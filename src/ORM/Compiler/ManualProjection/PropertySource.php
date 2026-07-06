<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\State\RecordState;

interface PropertySource
{
	public function getTargetRecord(): RecordState;

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array;
}
