<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Support\DefinitionNode;

class Registry extends DefinitionNode
{
	/** @var array<string, CollectionInterface> */
	public array $collections = [];

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
			$collection->bindDefinitionArray($items);
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
			return $name;
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

	/**
	 * @return array<string, mixed>
	 */
	protected function &getItemsReference(): array
	{
		return $this->items;
	}

	private function normalizeCollections(): void
	{
		$collections = $this->get('collections');
		foreach ($this->items['collections'] as $name => &$collection) {
			if (! is_array($collection)) {
				continue;
			}

			$collection['class'] ??= Collection::class;
			$collection['name'] ??= (string) $name;
			$collection['table'] ??= (string) $name;
			$collection['fields'] ??= [];
			$collection['relations'] ??= [];
			$collection['metadata'] ??= [];
		}

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
			'metadata' => [],
			'fields' => [],
			'relations' => [],
		];
	}
}
