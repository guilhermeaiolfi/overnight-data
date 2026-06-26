<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
final class StaticNode extends OutputNode
{
	/**
	 * @param list<string> $columns
	 * @param list<string> $identityFields
	 */
	public function __construct(array $columns, array $identityFields)
	{
		parent::__construct($columns, null);
		$this->assertFieldsExist($identityFields, $this->columns, 'Identity field');
		$this->setIdentityFields($this->validateFieldList($identityFields, 'Identity fields'));
	}

	public function push(array &$data): void
	{
		parent::push($data);

		foreach ($this->referenceIndexes as $referenceIndex) {
			$referenceIndex->add($data);
		}

		foreach ($this->nodes as $name => $node) {
			$data[$name] = $node instanceof CollectionNode ? [] : null;
		}
	}
}
