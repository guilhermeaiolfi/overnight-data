<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\Traits\DuplicateTrait;
use Throwable;

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
	 * @var list<string>
	 */
	private array $valueAliases = [];

	/**
	 * @param list<string> $columns
	 * @param list<string>|null $parentFields
	 */
	public function __construct(
		protected array $columns,
		?array $parentFields = null,
	) {
		$this->columns = $this->validateColumns($columns);
		$this->parentFields = $this->validateFieldList($parentFields ?? [], 'Parent reference fields');
	}

	public function parseRow(int $offset, array $row): int
	{
		$data = $this->fetchData($offset, $row);
		$innerOffset = 0;
		$relatedNodes = array_merge(
			$this->mergeParent === null ? [] : [$this->mergeParent],
			$this->nodes,
			$this->mergeSubclass,
		);

		if ($this->hasNullIdentityValue($data)) {
			return count($this->columns)
				+ array_reduce(
					$relatedNodes,
					static fn (int $count, AbstractNode $node): int => $node->isCollectionLike()
						? 0
						: $count + count($node->columns),
					0,
				);
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

			$innerColumns = $node->parseRow(count($this->columns) + $offset, $row);
			$offset += $innerColumns;
			$innerOffset += $innerColumns;
		}

		return count($this->columns) + $innerOffset;
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

	/**
	 * @param list<string> $aliases
	 */
	public function setValueAliases(array $aliases): void
	{
		$this->valueAliases = $aliases;
	}

	/**
	 * @param list<string> $columns
	 */
	public function appendColumns(array $columns): void
	{
		if ($columns === []) {
			return;
		}

		$this->columns = $this->validateColumns([
			...$this->columns,
			...$columns,
		]);
	}

	/**
	 * @return list<string>
	 */
	public function getValueAliasTraversal(): array
	{
		$aliases = $this->valueAliases;

		foreach (array_merge(
			$this->mergeParent === null ? [] : [$this->mergeParent],
			$this->nodes,
			$this->mergeSubclass,
		) as $node) {
			if (! $node->joined) {
				continue;
			}

			array_push($aliases, ...$node->getValueAliasTraversal());
		}

		return $aliases;
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
		$this->valueAliases = [];
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
	 * @return array<string, mixed>
	 */
	protected function fetchData(int $dataOffset, array $row): array
	{
		try {
			$data = array_combine(
				$this->columns,
				array_slice($row, $dataOffset, count($this->columns)),
			);
		} catch (Throwable $exception) {
			throw new ParserException('Unable to parse the incoming row: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
		}

		if ($data === false) {
			throw new ParserException('Unable to parse the incoming row because the configured column count does not match the incoming values.');
		}

		return $data;
	}

	/**
	 * @param list<string> $keys
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	protected function intersectData(array $keys, array $data): array
	{
		$result = [];

		foreach ($keys as $key) {
			if (! array_key_exists($key, $data)) {
				throw new ParserException(sprintf('Configured field `%s` is missing from the parsed record.', $key));
			}

			$result[$key] = $data[$key];
		}

		return $result;
	}

	/**
	 * @param list<string> $columns
	 * @return list<string>
	 */
	protected function validateColumns(array $columns): array
	{
		$validated = [];

		foreach ($columns as $column) {
			if (! is_string($column) || $column === '') {
				throw new ParserException('Configured columns must be non-empty strings.');
			}

			$validated[] = $column;
		}

		if (count(array_unique($validated)) !== count($validated)) {
			throw new ParserException('Duplicate column names are not allowed within one parser node.');
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
	 */
	protected function assertFieldsExist(array $fields, array $columns, string $label): void
	{
		foreach ($fields as $field) {
			if (! in_array($field, $columns, true)) {
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

		if ($records === []) {
			throw new ParserException(sprintf(
				'Undefined reference for parent fields `%s`.',
				implode(', ', $index->getFields()),
			));
		}

		return $records;
	}
}
