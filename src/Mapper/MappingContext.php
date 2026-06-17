<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolver\FieldResolverInterface;
use ON\Data\Mapper\Walker\WalkerInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingContext
{
	/**
	 * @param class-string<RepresentationInterface>|null $sourceRepresentation
	 * @param class-string<RepresentationInterface>|null $outputRepresentation
	 * @param class-string<WalkerInterface>|null $walkerClass
	 * @param class-string<WriterInterface>|null $writerClass
	 * @param list<class-string<FieldResolverInterface>> $resolverClasses
	 * @param list<mixed> $arguments
	 * @param array<int, true> $sourceObjectIds
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?string $sourceRepresentation = null,
		private ?string $outputRepresentation = null,
		private ?string $walkerClass = null,
		private ?string $writerClass = null,
		private array $resolverClasses = [],
		private array $arguments = [],
		private bool $collection = false,
		private string $path = '',
		private mixed $source = null,
		private mixed $target = null,
		private mixed $parentSource = null,
		private mixed $parentTarget = null,
		private array $sourceObjectIds = [],
	) {
	}

	public function getGateway(): ConversionGateway
	{
		return $this->gateway;
	}

	/**
	 * @return class-string<RepresentationInterface>|null
	 */
	public function getSourceRepresentation(): ?string
	{
		return $this->sourceRepresentation;
	}

	/**
	 * @return class-string<RepresentationInterface>|null
	 */
	public function getOutputRepresentation(): ?string
	{
		return $this->outputRepresentation;
	}

	/**
	 * @return class-string<WalkerInterface>|null
	 */
	public function getWalkerClass(): ?string
	{
		return $this->walkerClass;
	}

	/**
	 * @return class-string<WriterInterface>|null
	 */
	public function getWriterClass(): ?string
	{
		return $this->writerClass;
	}

	/**
	 * @return list<class-string<FieldResolverInterface>>
	 */
	public function getResolverClasses(): array
	{
		return $this->resolverClasses;
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

	public function getPath(): string
	{
		return $this->path;
	}

	public function getSource(): mixed
	{
		return $this->source;
	}

	public function getTarget(): mixed
	{
		return $this->target;
	}

	public function getParentSource(): mixed
	{
		return $this->parentSource;
	}

	public function getParentTarget(): mixed
	{
		return $this->parentTarget;
	}

	/**
	 * @param class-string<RepresentationInterface>|null $representation
	 */
	public function withSourceRepresentation(?string $representation): self
	{
		$clone = clone $this;
		$clone->sourceRepresentation = $representation;

		return $clone;
	}

	/**
	 * @param class-string<RepresentationInterface>|null $representation
	 */
	public function withOutputRepresentation(?string $representation): self
	{
		$clone = clone $this;
		$clone->outputRepresentation = $representation;

		return $clone;
	}

	/**
	 * @param class-string<WalkerInterface>|null $walker
	 */
	public function withWalkerClass(?string $walker): self
	{
		$clone = clone $this;
		$clone->walkerClass = $walker;

		return $clone;
	}

	/**
	 * @param class-string<WriterInterface>|null $writer
	 */
	public function withWriterClass(?string $writer): self
	{
		$clone = clone $this;
		$clone->writerClass = $writer;

		return $clone;
	}

	/**
	 * @param list<class-string<FieldResolverInterface>> $resolverClasses
	 */
	public function withResolverClasses(array $resolverClasses): self
	{
		$clone = clone $this;
		$clone->resolverClasses = $resolverClasses;

		return $clone;
	}

	/**
	 * @param class-string<FieldResolverInterface> $resolverClass
	 */
	public function withAddedResolverClass(string $resolverClass): self
	{
		$clone = clone $this;
		$clone->resolverClasses[] = $resolverClass;

		return $clone;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function withArguments(array $arguments): self
	{
		$clone = clone $this;
		$clone->arguments = $arguments;

		return $clone;
	}

	public function asCollection(): self
	{
		return $this->withCollection(true);
	}

	public function withCollection(bool $collection): self
	{
		$clone = clone $this;
		$clone->collection = $collection;

		return $clone;
	}

	public function withPathSegment(string $segment): self
	{
		$clone = clone $this;
		$clone->path = $this->path === '' ? $segment : $this->path . '.' . $segment;

		return $clone;
	}

	public function withPath(string $path): self
	{
		$clone = clone $this;
		$clone->path = $path;

		return $clone;
	}

	public function enter(
		mixed $source,
		mixed $target,
	): self {
		$clone = clone $this;
		$clone->source = $source;
		$clone->target = $target;

		if (is_object($source)) {
			if (
				$this->target !== null
				&& is_object($this->source)
				&& spl_object_id($this->source) === spl_object_id($source)
			) {
				return $clone;
			}

			$id = spl_object_id($source);
			if (isset($clone->sourceObjectIds[$id])) {
				$path = $clone->path === '' ? '(root)' : $clone->path;

				throw new MappingException(sprintf("Detected object cycle at path '%s'.", $path));
			}

			$clone->sourceObjectIds[$id] = true;
		}

		return $clone;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function forChild(
		mixed $source,
		array $arguments,
		bool $collection = false,
		bool $preserveComponentOverrides = false,
	): self {
		$clone = clone $this;
		$clone->parentSource = $this->source;
		$clone->parentTarget = $this->target;
		$clone->source = $source;
		$clone->target = null;
		$clone->arguments = $arguments;
		$clone->collection = $collection;

		if (! $preserveComponentOverrides) {
			$clone->walkerClass = null;
			$clone->writerClass = null;
		}

		return $clone;
	}
}
