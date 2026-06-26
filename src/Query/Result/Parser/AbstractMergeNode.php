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
abstract class AbstractMergeNode extends AbstractNode
{
	protected const OVERWRITE_DATA = false;

	/**
	 * @var list<array<string, mixed>>
	 */
	protected array $results = [];

	/**
	 * @param list<string> $columns
	 * @param list<string> $identityFields
	 * @param non-empty-list<string> $childFields
	 * @param non-empty-list<string> $parentFields
	 */
	public function __construct(
		private string $discriminatorValue,
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

	public function mergeInheritanceNodes(bool $includeDiscriminator = false): void
	{
		if ($this->parent === null) {
			return;
		}

		parent::mergeInheritanceNodes($includeDiscriminator);

		$discriminatorField = $includeDiscriminator
			? [self::DISCRIMINATOR_FIELD => $this->discriminatorValue]
			: [];

		foreach ($this->results as $item) {
			if ($this->hasOnlyNullChildFields($item)) {
				continue;
			}

			$this->parent->mergeData(
				$this->getParentReferenceIndex(),
				$this->orderedFieldValues($this->childFields, $item),
				$item + $discriminatorField,
				static::OVERWRITE_DATA,
			);
		}

		$this->results = [];
	}

	protected function push(array &$data): void
	{
		$this->results[] = &$data;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function hasOnlyNullChildFields(array $data): bool
	{
		foreach ($this->childFields as $field) {
			if (isset($data[$field])) {
				return false;
			}
		}

		return true;
	}
}
