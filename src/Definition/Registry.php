<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Exception\ConflictingPrimaryKeyDefinitionException;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\ForeignRegistryDefinitionException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Support\DefinitionNode;

class Registry extends DefinitionNode
{
	/** @var array<string, CollectionInterface> */
	public array $collections = [];

	/** @var array<string, ViewDefinitionInterface> */
	private array $views = [];

	/**
	 * @param array<string, mixed>|null $items
	 */
	public function __construct(?array $items = null)
	{
		parent::__construct($items ?? []);
		$this->normalizeCollections();
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

	public function collection(string $name): CollectionInterface
	{
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

	private function normalizeCollections(): void
	{
		foreach ($this->items['collections'] as $name => &$collection) {
			if (! is_array($collection)) {
				continue;
			}

			$collection['class'] ??= Collection::class;
			$collection['name'] ??= (string) $name;
			$collection['table'] ??= (string) $name;
			$collection['primaryKey'] = $this->normalizePrimaryKey((string) $name, $collection);
			$collection['fields'] ??= [];
			$collection['relations'] ??= [];
			$collection['metadata'] ??= [];
		}
		unset($collection);
		foreach ($this->items['views'] as $name => &$view) {
			if (! is_array($view)) {
				continue;
			}

			$view['class'] ??= ViewDefinition::class;
			$view['name'] ??= (string) $name;
			$view['source'] = isset($view['source']) && is_string($view['source']) && trim($view['source']) !== ''
				? trim($view['source'])
				: null;
			$view['fields'] ??= [];
			$view['relations'] ??= [];
			$view['metadata'] ??= [];
		}
		unset($view);
		$this->assertNoDefinitionNameConflicts();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function exportCollection(CollectionInterface $collection): array
	{
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
}
