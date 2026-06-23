<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;

final readonly class MappingNode
{
	private function __construct(
		private string|int|null $name,
		private mixed $value,
		private mixed $target,
		private MappingOptions $options,
		private ?self $parent,
	) {
	}

	public static function root(
		mixed $source,
		mixed $target,
		MappingOptions $options,
	): self {
		return new self(null, $source, $target, $options, null);
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

	public function getOptions(): MappingOptions
	{
		return $this->options;
	}

	/**
	 * @return list<mixed>
	 */
	public function getArguments(): array
	{
		return $this->options->getArguments();
	}

	public function isCollection(): bool
	{
		return $this->options->isCollection();
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

	public function withTarget(mixed $target): self
	{
		return new self(
			$this->name,
			$this->value,
			$target,
			$this->options,
			$this->parent,
		);
	}

	public function createChildNode(
		string|int $name,
		mixed $value,
	): self {
		return new self(
			$name,
			$value,
			null,
			$this->options,
			$this,
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
		$options = $this->options
			->withArguments($arguments)
			->withCollection($collection);

		if (! $preserveComponentOverrides) {
			$options = $options
				->withMapperClass(null)
				->withWriterClass(null);
		}

		return new self(
			$this->name,
			$this->value,
			$target,
			$options,
			$this->parent,
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
