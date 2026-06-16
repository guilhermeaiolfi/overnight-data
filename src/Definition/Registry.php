<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\InvalidDefinitionDataException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Support\Dot;

final class Registry extends Dot
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
		parent::__construct($items ?? self::definitionDefaults());
		$this->assertRootArrays();
		$this->assertNoDefinitionNameConflicts();
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

	public function collection(string $name, ?string $class = null): CollectionInterface
	{
		$name = $this->requireDefinitionName($name, 'collection');

		if (isset($this->items['collections'][$name]) && is_array($this->items['collections'][$name])) {
			$this->assertStoredClassMatches($this->items['collections'][$name], $class, 'collection');

			return $this->getCollection($name) ?? throw new InvalidArgumentException(sprintf("Collection '%s' is not defined.", $name));
		}

		$this->assertDefinitionNameAvailable($name);
		$class ??= Collection::class;
		$this->items['collections'][$name] = [];
		$slot = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::createCollection($this, $name, $slot, $class, [
			'table' => $name,
		]);

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$this->collections[$name]->setFileDefinitionLocation($trace[1]['file'] ?? __FILE__);

		return $this->collections[$name];
	}

	public function getCollection(string $name): ?CollectionInterface
	{
		if (isset($this->collections[$name])) {
			return $this->collections[$name];
		}

		if (! isset($this->items['collections'][$name]) || ! is_array($this->items['collections'][$name])) {
			return null;
		}

		$items = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::restoreCollection($this, $name, $items);

		return $this->collections[$name];
	}

	/**
	 * @return array<string, CollectionInterface>
	 */
	public function getCollections(): array
	{
		$collections = [];
		foreach (array_keys($this->items['collections']) as $name) {
			$collection = $this->getCollection((string) $name);
			if ($collection !== null) {
				$collections[$name] = $collection;
			}
		}

		return $collections;
	}

	public function hasCollection(string $name): bool
	{
		return isset($this->items['collections'][$name]) && is_array($this->items['collections'][$name]);
	}

	public function view(string $name, ?string $class = null): ViewDefinitionInterface
	{
		$name = $this->requireDefinitionName($name, 'view');

		if (isset($this->items['views'][$name]) && is_array($this->items['views'][$name])) {
			$this->assertStoredClassMatches($this->items['views'][$name], $class, 'view');

			return $this->getView($name) ?? throw new InvalidArgumentException(sprintf("View '%s' is not defined.", $name));
		}

		$this->assertDefinitionNameAvailable($name);
		$class ??= ViewDefinition::class;
		$this->items['views'][$name] = [];
		$slot = &$this->items['views'][$name];
		$this->views[$name] = DefinitionFactory::createView($this, $name, $slot, $class);

		return $this->views[$name];
	}

	public function getView(string $name): ?ViewDefinitionInterface
	{
		if (isset($this->views[$name])) {
			return $this->views[$name];
		}

		if (! isset($this->items['views'][$name]) || ! is_array($this->items['views'][$name])) {
			return null;
		}

		$items = &$this->items['views'][$name];
		$this->views[$name] = DefinitionFactory::restoreView($this, $name, $items);

		return $this->views[$name];
	}

	/**
	 * @return array<string, ViewDefinitionInterface>
	 */
	public function getViews(): array
	{
		$views = [];
		foreach (array_keys($this->items['views']) as $name) {
			$view = $this->getView((string) $name);
			if ($view !== null) {
				$views[$name] = $view;
			}
		}

		return $views;
	}

	public function hasView(string $name): bool
	{
		return isset($this->items['views'][$name]) && is_array($this->items['views'][$name]);
	}

	public function getDefinition(string $name): ?DefinitionInterface
	{
		return $this->getCollection($name) ?? $this->getView($name);
	}

	public function hasDefinition(string $name): bool
	{
		return $this->hasCollection($name) || $this->hasView($name);
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
	protected static function definitionDefaults(): array
	{
		return [
			'collections' => [],
			'views' => [],
		];
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

	private function assertDefinitionNameAvailable(string $name): void
	{
		if ($this->hasDefinition($name)) {
			throw new DefinitionNameConflictException(
				sprintf("Definition name '%s' is already in use.", $name)
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

	/**
	 * @param array<string, mixed> $items
	 */
	private function assertStoredClassMatches(array $items, ?string $class, string $context): void
	{
		if ($class === null) {
			return;
		}

		$storedClass = DefinitionFactory::requireStoredClass(
			$items,
			$context === 'collection' ? CollectionInterface::class : ViewDefinitionInterface::class,
			$context
		);

		if ($storedClass !== $class) {
			throw new InvalidArgumentException(
				sprintf("Cannot redefine %s with class '%s'; stored class is '%s'.", $context, $class, $storedClass)
			);
		}
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
