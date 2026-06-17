<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ReflectionProperty;

final readonly class MappingNode
{
	/**
	 * @param list<mixed> $arguments
	 */
	private function __construct(
		private string|int|null $name,
		private mixed $value,
		private mixed $target,
		private MappingContext $context,
		private ?self $parent,
		private array $arguments,
		private bool $collection,
		private ?ReflectionProperty $sourceProperty = null,
	) {
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public static function root(
		mixed $source,
		mixed $target,
		MappingContext $context,
		array $arguments = [],
		bool $collection = false,
	): self {
		return new self(null, $source, $target, $context, null, $arguments, $collection);
	}

	public function getName(): string|int|null
	{
		return $this->name;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getTarget(): mixed
	{
		return $this->target;
	}

	public function getContext(): MappingContext
	{
		return $this->context;
	}

	/**
	 * @return list<mixed>
	 */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public function isCollection(): bool
	{
		return $this->collection;
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function getPath(): string
	{
		if ($this->parent === null || $this->name === null) {
			return '';
		}

		$parentPath = $this->parent->getPath();
		$segment = (string) $this->name;

		return $parentPath === '' ? $segment : $parentPath . '.' . $segment;
	}

	public function getParentSource(): mixed
	{
		return $this->parent?->getValue();
	}

	public function getParentTarget(): mixed
	{
		return $this->parent?->getTarget();
	}

	public function getSourceProperty(): ?ReflectionProperty
	{
		return $this->sourceProperty;
	}

	public function withContext(MappingContext $context): self
	{
		return new self(
			$this->name,
			$this->value,
			$this->target,
			$context,
			$this->parent,
			$this->arguments,
			$this->collection,
			$this->sourceProperty,
		);
	}

	public function withTarget(mixed $target): self
	{
		return new self(
			$this->name,
			$this->value,
			$target,
			$this->context,
			$this->parent,
			$this->arguments,
			$this->collection,
			$this->sourceProperty,
		);
	}

	public function child(
		string|int $name,
		mixed $value,
		?ReflectionProperty $sourceProperty = null,
	): self {
		return new self(
			$name,
			$value,
			null,
			$this->context,
			$this,
			$this->arguments,
			false,
			$sourceProperty,
		);
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function forMapping(
		mixed $target,
		array $arguments,
		bool $collection = false,
		bool $preserveComponentOverrides = false,
	): self {
		$context = $this->context;
		if (! $preserveComponentOverrides) {
			$context = $context
				->withWalkerClass(null)
				->withWriterClass(null);
		}

		return new self(
			$this->name,
			$this->value,
			$target,
			$context,
			$this->parent,
			$arguments,
			$collection,
			$this->sourceProperty,
		);
	}

	public function assertNoObjectCycle(): void
	{
		if (! is_object($this->value)) {
			return;
		}

		$id = spl_object_id($this->value);
		$ancestor = $this->parent;

		while ($ancestor !== null) {
			$ancestorValue = $ancestor->getValue();
			if (is_object($ancestorValue) && spl_object_id($ancestorValue) === $id) {
				$path = $this->getPath();
				$path = $path === '' ? '(root)' : $path;

				throw new MappingException(sprintf("Detected object cycle at path '%s'.", $path));
			}

			$ancestor = $ancestor->getParent();
		}
	}
}
