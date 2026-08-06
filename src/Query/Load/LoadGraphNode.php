<?php

declare(strict_types=1);

namespace ON\Data\Query\Load;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Relation\LoadStrategy;

/**
 * One fetch unit in a {@see LoadGraph}: columns from one collection at one source path.
 *
 * Place/aliases are not stored here — those belong on {@see RepresentationSchema}.
 */
final class LoadGraphNode
{
	/** @var list<string> */
	private array $path;

	/** @var list<string> */
	private array $fields = [];

	/**
	 * @param list<string> $path relation path from the query root (empty = root collection)
	 */
	public function __construct(
		array $path,
		private readonly CollectionInterface $collection,
		private bool $loaded = false,
		private bool $visible = true,
		private bool $defaultFields = false,
		private ?LoadStrategy $strategy = null,
	) {
		$this->path = array_values($path);
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->path;
	}

	public function getPathKey(): string
	{
		return implode('.', $this->path);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function isRoot(): bool
	{
		return $this->path === [];
	}

	public function isLoaded(): bool
	{
		return $this->loaded;
	}

	public function isVisible(): bool
	{
		return $this->visible;
	}

	public function usesDefaultFields(): bool
	{
		return $this->defaultFields;
	}

	public function getStrategy(): ?LoadStrategy
	{
		return $this->strategy;
	}

	/**
	 * @return list<string>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function markLoaded(bool $loaded = true): void
	{
		$this->loaded = $loaded;
	}

	public function markVisible(bool $visible): void
	{
		$this->visible = $visible;
	}

	public function markDefaultFields(bool $defaultFields = true): void
	{
		$this->defaultFields = $defaultFields;
	}

	public function setStrategy(?LoadStrategy $strategy): void
	{
		$this->strategy = $strategy;
	}

	public function addField(string $fieldName): void
	{
		if ($fieldName === '' || in_array($fieldName, $this->fields, true)) {
			return;
		}

		$this->fields[] = $fieldName;
	}
}
