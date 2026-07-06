<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Common contract for manual projection sources that resolve to a concrete
 * RecordState (root target or relation item).
 *
 * Exists so PropertyRef and relation traversal share one identity surface for
 * SourceResolver and shape normalization.
 */
use ON\Data\ORM\State\RecordState;

interface PropertySource
{
	public function getTargetRecord(): RecordState;

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array;
}
