<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Exception\ConflictingPrimaryKeyDefinitionException;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\ForeignRegistryDefinitionException;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Exception\InvalidDefinitionDataException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Definition\View\ViewField;
use ON\Data\Support\DefinitionNode;

class Registry extends DefinitionNode
{
	/** @var array<string, CollectionInterface> */
	private array $collections = [];

	/** @var array<string, ViewDefinitionInterface> */
	private array $views = [];

	/**
	 * @param array<string, mixed>|null $items
	 */
	public function __construct(?array $items = null)
	{
		parent::__construct($items ?? []);
		$this->normalizeDefinitions();
	}

	protected static function definitionDefaults(): array
	{
		return [
			'collections' => [],
			'views' => [],
		];
	}

	public function register(CollectionInterface $collection): void
	{
		$data = $collection instanceof Collection ? $collection->all() : $this->exportCollection($collection);
		$name = $data['name'] ?? $collection->getName();
		$data['name'] = $name;
		$data['class'] = $data['class'] ?? $collection::class;

		$this->items['collections'][$name] = $data;
		foreach ($this->collections as $cachedName => $cachedCollection) {
			if ($cachedCollection === $collection) {
				unset($this->collections[$cachedName]);
			}
		}

		if ($collection instanceof Collection) {
			$items = &$this->items['collections'][$name];
			DefinitionFactory::rebind($collection, $items);
			$this->collections[$name] = $collection;

			return;
		}

		unset($this->collections[$name]);
		$this->getCollection($name);
	}

	public function getDefinitionFiles(): array
	{
		$files = [];
		foreach ($this->getCollections() as $collection) {
			$file = $collection->getFileDefinitionLocation();
			if (! is_string($file)) {
				continue;
			}

			$files[$file] ??= [];
			$files[$file][] = $collection->getName();
		}

		return $files;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		$items = parent::all();
		self::assertPlainData($items);

		return $items;
	}

	public function collection(string $name): CollectionInterface
	{
		$name = $this->normalizeDefinitionName($name, 'collection');
		$this->assertDefinitionNameAvailable($name, 'collection');
		$this->items['collections'][$name] = Collection::defaultDefinition($name);
		unset($this->collections[$name]);

		$collection = $this->getCollection($name);
		if ($collection === null) {
			$collection = new Collection($this);
			$this->register($collection->name($name)->table($name));
			$collection = $this->getCollection($name);
		}

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$collection?->setFileDefinitionLocation($trace[1]['file'] ?? __FILE__);

		return $collection ?? new Collection($this);
	}

	public function getCollection(string|CollectionInterface $name): ?CollectionInterface
	{
		if ($name instanceof CollectionInterface) {
			return $this->requireLocalDefinition($name);
		}

		if (isset($this->collections[$name])) {
			return $this->collections[$name];
		}

		if (! isset($this->items['collections'][$name]) || ! is_array($this->items['collections'][$name])) {
			return null;
		}

		$items = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::collection($this, $items);

		return $this->collections[$name];
	}

	/** @var CollectionInterface[] */
	public function getCollections(): array
	{
		$collections = [];
		foreach (array_keys($this->get('collections')) as $name) {
			$collection = $this->getCollection((string) $name);
			if ($collection !== null) {
				$collections[$name] = $collection;
			}
		}

		return $collections;
	}

	public function getInheritedCollections(): array
	{
		return [];
	}

	public function view(string $name): ViewDefinitionInterface
	{
		$name = $this->normalizeDefinitionName($name, 'view');
		$this->assertDefinitionNameAvailable($name, 'view');
		$this->items['views'][$name] = ViewDefinition::defaultDefinition($name);
		unset($this->views[$name]);

		$view = $this->getView($name);

		return $view ?? new ViewDefinition($this);
	}

	public function getView(string|ViewDefinitionInterface $view): ?ViewDefinitionInterface
	{
		if ($view instanceof ViewDefinitionInterface) {
			return $this->requireLocalDefinition($view);
		}

		if (isset($this->views[$view])) {
			return $this->views[$view];
		}

		if (! isset($this->items['views'][$view]) || ! is_array($this->items['views'][$view])) {
			return null;
		}

		$items = &$this->items['views'][$view];
		$this->views[$view] = DefinitionFactory::view($this, $items);

		return $this->views[$view];
	}

	public function hasView(string $name): bool
	{
		return isset($this->items['views'][$name]) && is_array($this->items['views'][$name]);
	}

	/**
	 * @return array<string, ViewDefinitionInterface>
	 */
	public function getViews(): array
	{
		$views = [];
		foreach (array_keys($this->get('views')) as $name) {
			$view = $this->getView((string) $name);
			if ($view !== null) {
				$views[$name] = $view;
			}
		}

		return $views;
	}

	public function getDefinition(string|DefinitionInterface $definition): ?DefinitionInterface
	{
		if ($definition instanceof DefinitionInterface) {
			return $this->requireLocalDefinition($definition);
		}

		return $this->getCollection($definition) ?? $this->getView($definition);
	}

	public function hasDefinition(string $name): bool
	{
		return $this->hasCollection($name) || $this->hasView($name);
	}

	public function hasCollection(string $name): bool
	{
		return isset($this->items['collections'][$name]) && is_array($this->items['collections'][$name]);
	}

	public function requireLocalDefinition(DefinitionInterface $definition): DefinitionInterface
	{
		if ($definition->getRegistry() !== $this) {
			throw new ForeignRegistryDefinitionException(
				sprintf("Definition '%s' belongs to a different registry.", $definition->getName())
			);
		}

		return $definition;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function &getItemsReference(): array
	{
		return $this->items;
	}

	private function normalizeDefinitions(): void
	{
		$this->items['collections'] = $this->normalizeCollectionDefinitions($this->items['collections']);
		$this->items['views'] = $this->normalizeViewDefinitions($this->items['views']);
		$this->assertNoDefinitionNameConflicts();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function exportCollection(CollectionInterface $collection): array
	{
		if ($collection instanceof DefinitionNode) {
			$all = $collection->all();
			self::assertPlainData($all);

			return $all;
		}

		return [
			'class' => $collection::class,
			'name' => $collection->getName(),
			'table' => $collection->getTable(),
			'database' => $collection->getDatabase(),
			'entity' => $collection->getEntity(),
			'parentCollection' => $collection->getParentCollection(),
			'scope' => $collection->getScope(),
			'repository' => $collection->getRepository(),
			'mapper' => $collection->getMapper(),
			'source' => $collection->getSource(),
			'note' => $collection->getNote(),
			'description' => $collection->getDescription(),
			'hidden' => $collection->isHidden(),
			'fileLocation' => $collection->getFileDefinitionLocation(),
			'primaryKey' => $collection->hasPrimaryKey() ? $collection->getPrimaryKey() : [],
			'metadata' => [],
			'fields' => [],
			'relations' => [],
		];
	}

	/**
	 * @param array<string, mixed> $definitions
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeCollectionDefinitions(array $definitions): array
	{
		$normalized = [];

		foreach ($definitions as $key => $definition) {
			$collection = is_array($definition) ? $definition : [];
			$name = $this->normalizeDefinitionName($collection['name'] ?? $key, 'collection');
			$collection['name'] = $name;
			$collection['class'] = DefinitionFactory::normalizeStoredClass(
				$collection,
				'class',
				Collection::class,
				CollectionInterface::class,
				'collection',
			);
			$collection = DefinitionFactory::materializeDefinitionArray($collection, $collection['class']);
			$collection['name'] = $name;
			$collection['table'] = is_string($collection['table'] ?? null) && $collection['table'] !== ''
				? $collection['table']
				: $name;
			$collection['fields'] = $this->normalizeFields($collection['fields'] ?? [], Field::class);
			$collection['relations'] = $this->normalizeRelations($collection['relations'] ?? []);
			$collection['metadata'] = $this->normalizePlainArray($collection['metadata'] ?? []);
			$collection['primaryKey'] = $this->normalizePrimaryKey($name, $collection);

			if (isset($normalized[$name])) {
				throw new DefinitionNameConflictException(
					sprintf("Collection name '%s' is defined more than once.", $name)
				);
			}

			$normalized[$name] = $collection;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $definitions
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeViewDefinitions(array $definitions): array
	{
		$normalized = [];

		foreach ($definitions as $key => $definition) {
			$view = is_array($definition) ? $definition : [];
			$name = $this->normalizeDefinitionName($view['name'] ?? $key, 'view');
			$view['name'] = $name;
			$view['class'] = DefinitionFactory::normalizeStoredClass(
				$view,
				'class',
				ViewDefinition::class,
				ViewDefinitionInterface::class,
				'view',
			);
			$view = DefinitionFactory::materializeDefinitionArray($view, $view['class']);
			$view['name'] = $name;
			$view['source'] = isset($view['source']) && is_string($view['source']) && trim($view['source']) !== ''
				? trim($view['source'])
				: null;
			$view['fields'] = $this->normalizeFields($view['fields'] ?? [], ViewField::class);
			$view['relations'] = $this->normalizeRelations($view['relations'] ?? []);
			$view['metadata'] = $this->normalizePlainArray($view['metadata'] ?? []);

			if (isset($normalized[$name])) {
				throw new DefinitionNameConflictException(
					sprintf("View name '%s' is defined more than once.", $name)
				);
			}

			$normalized[$name] = $view;
		}

		return $normalized;
	}

	/**
	 * @param mixed $definitions
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeFields(mixed $definitions, string $defaultClass): array
	{
		$normalized = [];
		if (! is_array($definitions)) {
			return $normalized;
		}

		foreach ($definitions as $key => $definition) {
			$field = is_array($definition) ? $definition : [];
			$name = $this->normalizeDefinitionName($field['name'] ?? $key, 'field');
			$field['name'] = $name;
			$field['class'] = DefinitionFactory::normalizeStoredClass(
				$field,
				'class',
				$defaultClass,
				FieldInterface::class,
				'field',
			);
			$field = DefinitionFactory::materializeDefinitionArray($field, $field['class']);
			$field['name'] = $name;
			$field['metadata'] = $this->normalizePlainArray($field['metadata'] ?? []);
			$this->normalizeNestedDisplay($field);
			$this->normalizeNestedInterface($field);
			$normalized[$name] = $field;
		}

		return $normalized;
	}

	/**
	 * @param mixed $definitions
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeRelations(mixed $definitions): array
	{
		$normalized = [];
		if (! is_array($definitions)) {
			return $normalized;
		}

		foreach ($definitions as $key => $definition) {
			$relation = is_array($definition) ? $definition : [];
			if (! array_key_exists('class', $relation)) {
				throw new InvalidDefinitionClassException('Relation definition is missing required class discriminator.');
			}

			$name = $this->normalizeDefinitionName($relation['name'] ?? $key, 'relation');
			$relation['name'] = $name;
			$relation['class'] = DefinitionFactory::normalizeStoredClass(
				$relation,
				'class',
				null,
				RelationInterface::class,
				'relation',
			);
			$relation = DefinitionFactory::materializeDefinitionArray($relation, $relation['class']);
			$relation['name'] = $name;
			$relation['metadata'] = $this->normalizePlainArray($relation['metadata'] ?? []);
			if (isset($relation['through']) && is_array($relation['through'])) {
				$relation['through'] = DefinitionFactory::materializeDefinitionArray($relation['through'], M2MThrough::class);
			}
			$this->normalizeNestedDisplay($relation);
			$this->normalizeNestedInterface($relation);
			$normalized[$name] = $relation;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function normalizeNestedDisplay(array &$definition): void
	{
		if (! array_key_exists('display', $definition) || ! is_array($definition['display'])) {
			return;
		}

		$display = $definition['display'];
		$display['class'] = DefinitionFactory::normalizeStoredClass(
			$display,
			'class',
			RawDisplay::class,
			DisplayInterface::class,
			'display',
		);
		$display = DefinitionFactory::materializeDefinitionArray($display, $display['class']);
		$definition['display'] = $display;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function normalizeNestedInterface(array &$definition): void
	{
		if (! array_key_exists('interface', $definition) || ! is_array($definition['interface'])) {
			return;
		}

		$interface = $definition['interface'];
		$interface['class'] = DefinitionFactory::normalizeStoredClass(
			$interface,
			'class',
			null,
			InterfaceInterface::class,
			'interface',
		);
		$interface = DefinitionFactory::materializeDefinitionArray($interface, $interface['class']);
		$definition['interface'] = $interface;
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private function normalizePlainArray(mixed $value): array
	{
		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<string, mixed> $collection
	 * @return list<string>
	 */
	private function normalizePrimaryKey(string $collectionName, array &$collection): array
	{
		$collection['fields'] ??= [];
		$fieldPrimaryKey = [];

		foreach ($collection['fields'] as $fieldName => &$field) {
			if (! is_array($field)) {
				continue;
			}

			$canonicalFieldName = is_string($fieldName) && $fieldName !== ''
				? $fieldName
				: (($field['name'] ?? null) && is_string($field['name']) ? $field['name'] : null);

			if (($field['pk'] ?? false) === true && is_string($canonicalFieldName) && $canonicalFieldName !== '') {
				$fieldPrimaryKey[] = $canonicalFieldName;
			}

			unset($field['pk']);
		}

		unset($field);

		$collectionPrimaryKey = $collection['primaryKey'] ?? null;
		if ($collectionPrimaryKey === null) {
			return $fieldPrimaryKey;
		}

		if (! is_array($collectionPrimaryKey)) {
			$collectionPrimaryKey = [];
		}

		$normalizedCollectionPrimaryKey = [];
		foreach ($collectionPrimaryKey as $fieldName) {
			if (! is_string($fieldName) || trim($fieldName) === '') {
				continue;
			}

			$normalizedCollectionPrimaryKey[] = trim($fieldName);
		}

		if ($fieldPrimaryKey !== [] && $normalizedCollectionPrimaryKey !== [] && $fieldPrimaryKey !== $normalizedCollectionPrimaryKey) {
			throw new ConflictingPrimaryKeyDefinitionException(
				sprintf(
					"Collection '%s' contains conflicting field-level and collection-level primary key definitions.",
					$collectionName
				)
			);
		}

		return $normalizedCollectionPrimaryKey !== [] ? $normalizedCollectionPrimaryKey : $fieldPrimaryKey;
	}

	private function normalizeDefinitionName(mixed $name, string $context): string
	{
		if (! is_string($name)) {
			throw new InvalidArgumentException(sprintf('%s name must be a string.', ucfirst($context)));
		}

		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException(sprintf('%s name cannot be empty.', ucfirst($context)));
		}

		return $name;
	}

	private function assertDefinitionNameAvailable(string $name, string $type): void
	{
		$conflictingType = $type === 'collection' ? 'view' : 'collection';
		$conflicts = $type === 'collection' ? $this->hasView($name) : $this->hasCollection($name);
		if ($conflicts) {
			throw new DefinitionNameConflictException(
				sprintf("Cannot create %s '%s' because a %s with that name already exists.", $type, $name, $conflictingType)
			);
		}
	}

	private function assertNoDefinitionNameConflicts(): void
	{
		$conflicts = array_intersect(array_keys($this->items['collections']), array_keys($this->items['views']));
		if ($conflicts === []) {
			return;
		}

		$name = (string) array_values($conflicts)[0];

		throw new DefinitionNameConflictException(
			sprintf("Definition name '%s' is used by both a collection and a view.", $name)
		);
	}

	private static function assertPlainData(mixed $value, string $path = 'root'): void
	{
		if (is_array($value)) {
			foreach ($value as $key => $nestedValue) {
				self::assertPlainData($nestedValue, sprintf('%s[%s]', $path, (string) $key));
			}

			return;
		}

		if (is_object($value)) {
			throw new InvalidDefinitionDataException(sprintf('Definition export contains object data at %s.', $path));
		}

		if (is_resource($value)) {
			throw new InvalidDefinitionDataException(sprintf('Definition export contains resource data at %s.', $path));
		}

		if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
			throw new InvalidDefinitionDataException(sprintf('Definition export contains unsupported data at %s.', $path));
		}
	}
}
