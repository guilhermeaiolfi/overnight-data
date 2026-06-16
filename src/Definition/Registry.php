<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;

class Registry
{
	public array $collections = [];

	protected array $files = [];

	public function register(CollectionInterface $collection): void
	{
		$this->collections[$collection->getName()] = $collection;
	}

	public function getDefinitionFiles(): array
	{
		$files = [];
		foreach ($this->collections as $name => $collection) {
			$file = $collection->getFileDefinitionLocation();
			if (! isset($files[$file]) || ! is_array($files[$file])) {
				$this->files[$file] = [];
			}
			$files[$file][] = $collection->getName();
		}

		return $files;
	}

	public function collection(string $name): CollectionInterface
	{

		$collection = new Collection($this);
		$this->collections[$name] = $collection;

		// keep track of all files containing colletion definitions
		// that info is useful when caching, to see if we are up to date
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$collection->setFileDefinitionLocation($trace[1]["file"] ?? __FILE__);

		$collection->name($name);

		// by default, set the table name as the same as the collection name
		$collection->table($name);

		return $collection;
	}

	public function getCollection(string|CollectionInterface $name): ?CollectionInterface
	{
		if ($name instanceof CollectionInterface) {
			return $name;
		}

		return $this->collections[$name] ?? null;
	}

	/** @var CollectionInterface[] */
	public function getCollections(): array
	{
		return $this->collections;
	}

	public function getInheritedCollections(): array
	{
		// TODO: look in cycle Schema::getInheritedRoles;

		return [];
	}
}
