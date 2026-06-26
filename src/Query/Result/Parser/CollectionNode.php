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
final class CollectionNode extends AbstractNode
{
	/**
	 * @param list<string> $columns
	 * @param list<string> $identityFields
	 * @param non-empty-list<string> $childFields
	 * @param non-empty-list<string> $parentFields
	 */
	public function __construct(
		array $columns,
		array $identityFields,
		protected array $childFields,
		array $parentFields,
	) {
		parent::__construct($columns, $parentFields);

		$this->childFields = $this->validateFieldList($childFields, 'Child reference fields', false);
		$this->assertFieldsExist($this->childFields, $this->columns, 'Child reference field');
		$this->assertFieldsExist($identityFields, $this->columns, 'Identity field');

		if (count($this->childFields) !== count($this->parentFields)) {
			throw new ParserException('Parent and child reference field counts must match.');
		}

		$this->setIdentityFields($this->validateFieldList($identityFields, 'Identity fields'));
	}

	protected function push(array &$data): void
	{
		if ($this->parent === null) {
			throw new ParserException('Unable to register a collection child record because the parent node is missing.');
		}

		foreach ($this->childFields as $field) {
			if ($data[$field] === null) {
				return;
			}
		}

		$this->parent->mountArray(
			$this->container ?? throw new ParserException('Unable to mount a collection child because its container name is undefined.'),
			$this->getParentReferenceIndex(),
			$this->orderedFieldValues($this->childFields, $data),
			$data,
		);
	}
}
