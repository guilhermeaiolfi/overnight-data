<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\ForeignRegistryDefinitionException;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Exception\InvalidDefinitionDataException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewDefinitionInterface;
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
		$this->assertRootArrays();
		$this->assertRootDefinitions('collections', CollectionInterface::class, 'collection');
		$this->assertRootDefinitions('views', ViewDefinitionInterface::class, 'view');
		$this->assertNoDefinitionNameConflicts();
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
		$this->requireLocalDefinition($collection);

		$data = DefinitionFactory::export($collection, CollectionInterface::class, 'collection');
		self::assertPlainData($data);

		$name = $data['name'] ?? null;
		$contextName = is_string($name) ? $name : $collection->getName();
		$name = $this->requireDefinitionName($name, 'collection');
		$this->assertDefinitionNameAvailable($name, 'collection');
		$this->assertCollectionRootClass($data, $contextName);

		$data['name'] = $name;
		$this->items['collections'][$name] = $data;

		foreach ($this->collections as $cachedName => $cachedCollection) {
			if ($cachedCollection === $collection && $cachedName !== $name) {
				unset($this->collections[$cachedName]);
			}
		}

		unset($this->collections[$name]);
		$items = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::collection($this, $items);
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
		$name = $this->requireDefinitionName($name, 'collection');
		$this->assertDefinitionNameAvailable($name, 'collection');
		$this->items['collections'][$name] = Collection::defaultDefinition($name);
		unset($this->collections[$name]);

		$items = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::collection($this, $items);

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$this->collections[$name]->setFileDefinitionLocation($trace[1]['file'] ?? __FILE__);

		return $this->collections[$name];
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

	/** @return CollectionInterface[] */
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
		$name = $this->requireDefinitionName($name, 'view');
		$this->assertDefinitionNameAvailable($name, 'view');
		$this->items['views'][$name] = ViewDefinition::defaultDefinition($name);
		unset($this->views[$name]);

		$items = &$this->items['views'][$name];
		$this->views[$name] = DefinitionFactory::view($this, $items);

		return $this->views[$name];
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

	private function assertRootArrays(): void
	{
		if (! is_array($this->items['collections'])) {
			throw new InvalidArgumentException('Registry collections must be an array.');
		}

		if (! is_array($this->items['views'])) {
			throw new InvalidArgumentException('Registry views must be an array.');
		}
	}

	/**
	 * @param class-string $expectedType
	 */
	private function assertRootDefinitions(string $rootKey, string $expectedType, string $context): void
	{
		foreach ($this->items[$rootKey] as $name => $definition) {
			if (! is_array($definition)) {
				throw new InvalidArgumentException(sprintf('%s definition "%s" must be an array.', ucfirst($context), (string) $name));
			}

			$storedName = $this->requireDefinitionName($definition['name'] ?? null, $context);
			if ((string) $name !== $storedName) {
				throw new InvalidArgumentException(
					sprintf("Stored %s name '%s' must match root key '%s'.", $context, $storedName, (string) $name)
				);
			}

			DefinitionFactory::requireStoredClass($definition, $expectedType, $context);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function assertCollectionRootClass(array $data, string $contextName): void
	{
		try {
			DefinitionFactory::requireStoredClass($data, CollectionInterface::class, 'collection');
		} catch (InvalidDefinitionClassException $exception) {
			throw new InvalidDefinitionClassException(
				sprintf('Invalid collection "%s": %s', $contextName, $exception->getMessage()),
				0,
				$exception
			);
		}
	}

	private function requireDefinitionName(mixed $name, string $context): string
	{
		if (! is_string($name)) {
			throw new InvalidArgumentException(sprintf('%s name must be a string.', ucfirst($context)));
		}

		if (trim($name) === '') {
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
