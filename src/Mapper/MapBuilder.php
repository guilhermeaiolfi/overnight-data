<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use JsonException;
use ON\Data\Mapper\Exception\InvalidMapTargetException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MapBuilder
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
		private mixed $source,
		private ConversionGateway $gateway,
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

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	public function from(string $representation): self
	{
		$clone = clone $this;
		$clone->sourceRepresentation = $representation;

		return $clone;
	}

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	public function as(string $representation): self
	{
		$clone = clone $this;
		$clone->outputRepresentation = $representation;

		return $clone;
	}

	/**
	 * @param class-string<MapperInterface> $mapper
	 */
	public function mapper(string $mapper): self
	{
		$clone = clone $this;
		$clone->mapperClass = $mapper;

		return $clone;
	}

	/**
	 * @param class-string<WriterInterface> $writer
	 */
	public function writer(string $writer): self
	{
		$clone = clone $this;
		$clone->writerClass = $writer;

		return $clone;
	}

	/**
	 * @param class-string<NodeResolverInterface> $resolver
	 */
	public function resolver(string $resolver): self
	{
		$clone = clone $this;
		$clone->resolverClasses[] = $resolver;

		return $clone;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function args(mixed ...$arguments): self
	{
		$clone = clone $this;
		$clone->arguments = $arguments;

		return $clone;
	}

	public function fieldMap(FieldMap $fieldMap): self
	{
		$clone = clone $this;
		$clone->fieldMap = $fieldMap;

		return $clone;
	}

	public function collection(): self
	{
		$clone = clone $this;
		$clone->collection = true;

		return $clone;
	}

	public function to(mixed $target): mixed
	{
		if (is_string($target) && class_exists($target) && is_a($target, RepresentationInterface::class, true)) {
			throw new InvalidMapTargetException(
				sprintf("'%s' is a representation class. Call as() to configure the output representation.", $target)
			);
		}

		return $this->gateway
			->getMapperManager()
			->map($this->source, $target, $this->createOptions());
	}

	public function toJson(): string
	{
		$value = $this->to([]);
		if (! is_array($value)) {
			throw new MappingException('Mapped result is not an array.');
		}

		try {
			return json_encode($value, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new MappingException('Unable to encode mapped result as JSON.', 0, $exception);
		}
	}

	private function createOptions(): MappingOptions
	{
		$options = new MappingOptions(
			$this->gateway,
			$this->sourceRepresentation,
			$this->outputRepresentation,
			$this->mapperClass,
			$this->writerClass,
			$this->resolverClasses,
			$this->arguments,
			$this->fieldMap,
		);

		if ($this->collection) {
			$options = $options->asCollection();
		}

		return $options;
	}
}
