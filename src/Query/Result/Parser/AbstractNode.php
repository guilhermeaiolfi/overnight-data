<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\Traits\DuplicateTrait;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
abstract class AbstractNode
{
	use DuplicateTrait;

	protected const LAST_REFERENCE = ['~'];
	protected const DISCRIMINATOR_FIELD = '@role';

	protected bool $joined = false;

	/**
	 * @var list<string>
	 */
	protected array $parentFields;

	protected ?string $container = null;
	protected ?self $parent = null;

	/**
	 * @var array<string, AbstractNode>
	 */
	protected array $nodes = [];

	protected ?ParentMergeNode $mergeParent = null;

	/**
	 * @var list<SubclassMergeNode>
	 */
	protected array $mergeSubclass = [];

	protected ?ReferenceIndex $parentReferenceIndex = null;

	/**
	 * @var array<string, ReferenceIndex>
	 */
	protected array $referenceIndexes = [];

	/**
	 * Bag name => SQL/result key. A list constructor argument is stored as an identity map.
	 *
	 * @var array<string, string>
	 */
	protected array $columns;

	/**
	 * @param array<string, string>|list<string> $columns bag name => SQL key; a list is an identity map
	 * @param list<string>|null $parentFields
	 */
	public function __construct(
		array $columns,
		?array $parentFields = null,
	) {
		$this->columns = $this->validateColumns($columns);
		$this->parentFields = $this->validateFieldList($parentFields ?? [], 'Parent reference fields');
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function parseRow(array $row): void
	{
		$data = $this->fetchData($row);
		$relatedNodes = array_merge(
			$this->mergeParent === null ? [] : [$this->mergeParent],
			$this->nodes,
			$this->mergeSubclass,
		);

		if ($this->hasNullIdentityValue($data)) {
			return;
		}

		if ($this->deduplicate($data)) {
			foreach ($this->referenceIndexes as $referenceIndex) {
				$referenceIndex->add($data);
			}

			foreach ($this->nodes as $name => $node) {
				$data[$name] = $node->isCollectionLike() ? [] : null;
			}

			$this->push($data);
		} elseif ($this->parent !== null) {
			$this->push($data);
		}

		foreach ($relatedNodes as $node) {
			if (! $node->joined) {
				continue;
			}

			$node->parseRow($row);
		}
	}

	/**
	 * @return list<array<string, scalar>>
	 */
	public function getReferenceValues(): array
	{
		if ($this->parent === null) {
			throw new ParserException('Unable to aggregate reference values because the parent node is missing.');
		}

		if ($this->parentReferenceIndex === null) {
			return [];
		}

		return $this->parentReferenceIndex->getReferenceValues();
	}

	public function linkNode(?string $container, self $node): void
	{
		$this->attachNode($container, $node, false);
	}

	public function joinNode(?string $container, self $node): void
	{
		$this->attachNode($container, $node, true);
	}

	public function getNode(string $container): self
	{
		if (! isset($this->nodes[$container])) {
			throw new ParserException(sprintf('Undefined child node `%s`.', $container));
		}

		return $this->nodes[$container];
	}

	public function getParentMergeNode(): ?ParentMergeNode
	{
		return $this->mergeParent;
	}

	/**
	 * @return list<SubclassMergeNode>
	 */
	public function getSubclassMergeNodes(): array
	{
		return $this->mergeSubclass;
	}

	public function getRelationAttachmentNode(): self
	{
		return $this;
	}

	public function isCollectionLike(): bool
	{
		return false;
	}

	public function mergeInheritanceNodes(bool $includeDiscriminator = false): void
	{
		$this->mergeParent?->mergeInheritanceNodes();

		foreach ($this->mergeSubclass as $subclassNode) {
			$subclassNode->mergeInheritanceNodes($includeDiscriminator);
		}
	}

	public function __destruct()
	{
		$this->parent = null;
		$this->nodes = [];
		$this->mergeParent = null;
		$this->mergeSubclass = [];
		$this->referenceIndexes = [];
		$this->duplicates = [];
	}

	/**
	 * @return list<string>
	 */
	protected function getParentFields(): array
	{
		return $this->parentFields;
	}

	protected function getParentReferenceIndex(): ReferenceIndex
	{
		return $this->parentReferenceIndex
			?? throw new ParserException('The node has not been attached to a parent reference index.');
	}

	/**
	 * @param list<string> $fields
	 * @param array<string, mixed> $data
	 * @return list<scalar>
	 */
	protected function orderedFieldValues(array $fields, array $data): array
	{
		$values = [];

		foreach ($fields as $field) {
			if (! array_key_exists($field, $data)) {
				throw new ParserException(sprintf('Configured field `%s` is missing from the parsed record.', $field));
			}

			$value = $data[$field];

			if (! is_scalar($value)) {
				throw new ParserException(sprintf('Field `%s` must contain a scalar value, `%s` given.', $field, get_debug_type($value)));
			}

			$values[] = $value;
		}

		return $values;
	}

	protected function mount(string $container, ReferenceIndex $index, array $criteria, array &$data): void
	{
		$records = &$this->recordsForCriteria($index, $criteria);

		foreach ($records as &$record) {
			if (isset($record[$container])) {
				$data = &$record[$container];
			} else {
				$record[$container] = &$data;
			}
		}
		unset($record);
	}

	protected function mountArray(string $container, ReferenceIndex $index, array $criteria, array &$data): void
	{
		$records = &$this->recordsForCriteria($index, $criteria);

		foreach ($records as &$record) {
			if (! in_array($data, $record[$container], true)) {
				$record[$container][] = &$data;
			}
		}
		unset($record);
	}

	protected function mergeData(ReferenceIndex $index, array $criteria, array $data, bool $overwrite): void
	{
		$records = &$this->recordsForCriteria($index, $criteria);

		foreach ($records as &$record) {
			$record = $overwrite ? array_merge($record, $data) : array_merge($data, $record);
		}
		unset($record);
	}

	abstract protected function push(array &$data): void;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function fetchData(array $row): array
	{
		$data = [];

		foreach ($this->columns as $bagName => $sqlKey) {
			$data[$bagName] = $row[$sqlKey] ?? null;
		}

		return $data;
	}

	/**
	 * @param array<string, string>|list<string> $columns
	 * @return array<string, string>
	 */
	protected function validateColumns(array $columns): array
	{
		if ($columns !== [] && array_is_list($columns)) {
			$identity = [];

			foreach ($columns as $column) {
				if (! is_string($column) || $column === '') {
					throw new ParserException('Configured columns must be non-empty strings.');
				}

				if (isset($identity[$column])) {
					throw new ParserException('Duplicate column names are not allowed within one parser node.');
				}

				$identity[$column] = $column;
			}

			return $identity;
		}

		$validated = [];

		foreach ($columns as $bagName => $sqlKey) {
			if (! is_string($bagName) || $bagName === '' || ! is_string($sqlKey) || $sqlKey === '') {
				throw new ParserException('Column remap keys and values must be non-empty strings.');
			}

			$validated[$bagName] = $sqlKey;
		}

		return $validated;
	}

	/**
	 * @param list<string> $fields
	 * @return list<string>
	 */
	protected function validateFieldList(array $fields, string $label, bool $allowEmpty = true): array
	{
		if ($fields === [] && ! $allowEmpty) {
			throw new ParserException(sprintf('%s must not be empty.', $label));
		}

		$validated = [];

		foreach ($fields as $field) {
			if (! is_string($field) || $field === '') {
				throw new ParserException(sprintf('%s must contain only non-empty strings.', $label));
			}

			$validated[] = $field;
		}

		return $validated;
	}

	/**
	 * @param list<string> $fields
	 * @param array<string, string> $columns
	 */
	protected function assertFieldsExist(array $fields, array $columns, string $label): void
	{
		foreach ($fields as $field) {
			if (! array_key_exists($field, $columns)) {
				throw new ParserException(sprintf('%s `%s` does not exist in the configured columns.', $label, $field));
			}
		}
	}

	private function attachNode(?string $container, self $node, bool $joined): void
	{
		$this->assertAttachableNode($container, $node);

		$node->joined = $joined;
		$node->parent = $this;
		$node->container = $container;
		$node->parentReferenceIndex = $node->parentFields === []
			? null
			: $this->referenceIndexForFields($node->parentFields);

		if ($container !== null) {
			$this->nodes[$container] = $node;

			return;
		}

		if ($node instanceof ParentMergeNode) {
			$this->mergeParent = $node;

			return;
		}

		$this->mergeSubclass[] = $node;
	}

	protected function attachProxiedNode(string $container, ProxyNode $proxy, self $node): void
	{
		$this->assertAttachableProxiedNode($container, $proxy, $node);

		$node->joined = false;
		$node->parent = $this;
		$node->container = $container;
		$node->parentReferenceIndex = $node->parentFields === []
			? null
			: $this->referenceIndexForFields($node->parentFields);
	}

	private function assertAttachableNode(?string $container, self $node): void
	{
		if ($node === $this) {
			throw new ParserException('A parser node cannot be attached to itself.');
		}

		for ($ancestor = $this; $ancestor !== null; $ancestor = $ancestor->parent) {
			if ($ancestor === $node) {
				throw new ParserException('A parser node cannot be attached to one of its descendants.');
			}
		}

		if ($node->parent !== null) {
			throw new ParserException('A parser node can only be attached to one parent.');
		}

		if ($container !== null) {
			if ($container === '') {
				throw new ParserException('A child container name must be a non-empty string.');
			}

			if (isset($this->nodes[$container])) {
				throw new ParserException(sprintf('A child node is already attached to the `%s` container.', $container));
			}
		} elseif (! $node instanceof AbstractMergeNode) {
			throw new ParserException('Only merge nodes may be attached without a named child container.');
		}

		if ($node instanceof ParentMergeNode && $this->mergeParent !== null) {
			throw new ParserException('Only one parent merge node may be attached to a parser node.');
		}

		$this->assertFieldsExist($node->getParentFields(), $this->columns, 'Parent reference field');
	}

	private function assertAttachableProxiedNode(string $container, ProxyNode $proxy, self $node): void
	{
		if (! isset($this->nodes[$container]) || $this->nodes[$container] !== $proxy) {
			throw new ParserException(sprintf('Unable to attach a proxied child because the `%s` proxy container is undefined.', $container));
		}

		if ($node instanceof AbstractMergeNode) {
			throw new ParserException('Merge nodes cannot be attached as proxied children.');
		}

		if ($node === $this || $node === $proxy) {
			throw new ParserException('A parser node cannot be attached to itself.');
		}

		for ($ancestor = $this; $ancestor !== null; $ancestor = $ancestor->parent) {
			if ($ancestor === $node) {
				throw new ParserException('A parser node cannot be attached to one of its descendants.');
			}
		}

		if ($node->parent !== null) {
			throw new ParserException('A parser node can only be attached to one parent.');
		}

		$this->assertFieldsExist($node->getParentFields(), $this->columns, 'Parent reference field');
	}

	/**
	 * @param list<string> $fields
	 */
	private function referenceIndexForFields(array $fields): ReferenceIndex
	{
		$key = json_encode($fields, JSON_THROW_ON_ERROR);

		if (! isset($this->referenceIndexes[$key])) {
			$this->referenceIndexes[$key] = new ReferenceIndex($fields);
		}

		return $this->referenceIndexes[$key];
	}

	/**
	 * @param list<scalar>|array{0: '~'} $criteria
	 * @return array<int, array<string, mixed>>
	 */
	private function &recordsForCriteria(ReferenceIndex $index, array $criteria): array
	{
		if ($criteria === self::LAST_REFERENCE) {
			$lastReferenceValues = $index->getLastReferenceValues();

			if ($lastReferenceValues === null) {
				$records = [];

				return $records;
			}

			$criteria = array_values($lastReferenceValues);
		}

		$records = $index->getRecordsByValues($criteria);

		// Separate-query loaders can still surface orphan FK values (or historically
		// int/string mismatched keys). Drop the child instead of failing the whole read.
		if ($records === []) {
			$empty = [];

			return $empty;
		}

		return $records;
	}
}
