<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\ORM\Record\RecordState;
/**
 * Common contract for manual representation sources that resolve to a concrete
 * RecordState (root target or relation item).
 *
 * Exists so PropertyRef and relation traversal share one identity surface for
 * ManualRepresentationSourceResolver and shape normalization.
 */
interface ManualRepresentationSourceInterface
{
	public function getTargetRecord(): RecordState;

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array;
}
