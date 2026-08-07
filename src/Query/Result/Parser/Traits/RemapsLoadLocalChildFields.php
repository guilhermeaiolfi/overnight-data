<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser\Traits;

use ON\Data\Query\Result\Parser\AbstractNode;

/**
 * Remaps child FK column names when parser nodes switch from place keys to
 * load-local value aliases (proposal 0003 cleanup).
 *
 * @phpstan-require-extends AbstractNode
 * @property list<string> $childFields
 */
trait RemapsLoadLocalChildFields
{
	/**
	 * @param array<string, string> $placeToLoad
	 */
	protected function remapLoadLocalColumnReferences(array $placeToLoad): void
	{
		parent::remapLoadLocalColumnReferences($placeToLoad);
		$this->childFields = $this->remapFieldList($this->childFields, $placeToLoad);
	}
}
