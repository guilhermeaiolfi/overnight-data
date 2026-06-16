<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Representation\RepresentationInterface;

final class MappingContext
{
	/**
	 * @param class-string<RepresentationInterface>|null $sourceRepresentation
	 * @param class-string<RepresentationInterface>|null $outputRepresentation
	 * @param class-string<MapperInterface>|null $mapperClass
	 * @param list<mixed> $arguments
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?string $sourceRepresentation = null,
		private ?string $outputRepresentation = null,
		private ?string $mapperClass = null,
		private array $arguments = [],
		private bool $collection = false,
		private string $path = '',
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
	 * @param list<mixed> $arguments
	 */
	public function withMapperClass(?string $mapper, array $arguments = []): self
	{
		$clone = clone $this;
		$clone->mapperClass = $mapper;
		$clone->arguments = $arguments;

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
}
