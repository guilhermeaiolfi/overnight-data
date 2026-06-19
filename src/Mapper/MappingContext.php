<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingContext
{
	/**
	 * @param class-string<RepresentationInterface>|null $sourceRepresentation
	 * @param class-string<RepresentationInterface>|null $outputRepresentation
	 * @param class-string<MapperInterface>|null $mapperClass
	 * @param class-string<WriterInterface>|null $writerClass
	 * @param list<class-string<NodeResolverInterface>> $resolverClasses
	 * @param list<mixed> $arguments
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?string $sourceRepresentation = null,
		private ?string $outputRepresentation = null,
		private ?string $mapperClass = null,
		private ?string $writerClass = null,
		private array $resolverClasses = [],
		private array $arguments = [],
		private ?FieldMap $fieldMap = null,
		private bool $collection = false,
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
	 * @return class-string<MapperInterface>|null
	 */
	public function getMapperClass(): ?string
	{
		return $this->mapperClass;
	}

	/**
	 * @return class-string<WriterInterface>|null
	 */
	public function getWriterClass(): ?string
	{
		return $this->writerClass;
	}

	/**
	 * @return list<class-string<NodeResolverInterface>>
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

	public function getFieldMap(): ?FieldMap
	{
		return $this->fieldMap;
	}

	public function isCollection(): bool
	{
		return $this->collection;
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
	 * @param class-string<MapperInterface>|null $mapper
	 */
	public function withMapperClass(?string $mapper): self
	{
		$clone = clone $this;
		$clone->mapperClass = $mapper;

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
	 * @param list<class-string<NodeResolverInterface>> $resolverClasses
	 */
	public function withResolverClasses(array $resolverClasses): self
	{
		$clone = clone $this;
		$clone->resolverClasses = $resolverClasses;

		return $clone;
	}

	/**
	 * @param class-string<NodeResolverInterface> $resolverClass
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

	public function withFieldMap(?FieldMap $fieldMap): self
	{
		$clone = clone $this;
		$clone->fieldMap = $fieldMap;

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
}
