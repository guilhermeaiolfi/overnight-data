<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Exception\InvalidDefinitionDataException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Support\Dot;

final class Registry extends Dot
{
	/** @var array<string, CollectionInterface> */
	private array $collections = [];

	/**
	 * @param array<string, mixed>|null $items
	 */
	public function __construct(?array $items = null)
	{
		parent::__construct($items ?? ['collections' => []]);
		if (! is_array($this->items['collections'] ?? null)) {
			throw new InvalidArgumentException('Registry collections must be an array.');
		}
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
		if (trim($name) === '') {
			throw new InvalidArgumentException('Collection name cannot be empty.');
		}

		if (isset($this->items['collections'][$name]) && is_array($this->items['collections'][$name])) {
			$this->assertStoredClassMatches($this->items['collections'][$name], $class);

			return $this->getCollection($name) ?? throw new InvalidArgumentException(sprintf("Collection '%s' is not defined.", $name));
		}

		$class ??= Collection::class;
		$this->items['collections'][$name] = [];
		$slot = &$this->items['collections'][$name];
		$this->collections[$name] = DefinitionFactory::create($this, $name, $slot, $class, CollectionInterface::class, 'collection', [
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
		$this->collections[$name] = DefinitionFactory::restore($this, $name, $items, CollectionInterface::class, 'collection');

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

	public function getDefinition(string $name): ?DefinitionInterface
	{
		return $this->getCollection($name);
	}

	public function hasDefinition(string $name): bool
	{
		return $this->hasCollection($name);
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
	 * @param array<string, mixed> $items
	 */
	private function assertStoredClassMatches(array $items, ?string $class): void
	{
		if ($class === null) {
			return;
		}

		$storedClass = DefinitionFactory::requireStoredClass(
			$items,
			CollectionInterface::class,
			'collection'
		);

		if ($storedClass !== $class) {
			throw new InvalidArgumentException(
				sprintf("Cannot redefine collection with class '%s'; stored class is '%s'.", $class, $storedClass)
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
