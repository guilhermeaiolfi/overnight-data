<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ReflectionProperty;

final readonly class MappingNode
{
	private function __construct(
		private string|int|null $name,
		private mixed $value,
		private mixed $target,
		private MappingContext $context,
		private ?self $parent,
		private ?ReflectionProperty $sourceProperty = null,
	) {
	}

	public static function root(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): self {
		return new self(null, $source, $target, $context, null);
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
		return $this->context->getArguments();
	}

	public function isCollection(): bool
	{
		return $this->context->isCollection();
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

	public function withTarget(mixed $target): self
	{
		return new self(
			$this->name,
			$this->value,
			$target,
			$this->context,
			$this->parent,
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
		$context = $this->context
			->withArguments($arguments)
			->withCollection($collection);

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
