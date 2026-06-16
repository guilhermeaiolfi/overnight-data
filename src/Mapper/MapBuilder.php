<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use JsonException;
use ON\Data\Mapper\Exception\InvalidMapTargetException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\RepresentationInterface;

final class MapBuilder
{
	/**
	 * @param class-string<RepresentationInterface>|null $sourceRepresentation
	 * @param class-string<RepresentationInterface>|null $outputRepresentation
	 * @param class-string<MapperInterface>|null $mapperClass
	 * @param list<mixed> $arguments
	 */
	public function __construct(
		private mixed $source,
		private ConversionGateway $gateway,
		private ?string $sourceRepresentation = null,
		private ?string $outputRepresentation = null,
		private ?string $mapperClass = null,
		private array $arguments = [],
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
	public function using(string $mapper, mixed ...$arguments): self
	{
		$clone = clone $this;
		$clone->mapperClass = $mapper;
		$clone->arguments = $arguments;

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
			->getMappers()
			->map($this->source, $target, $this->createContext());
	}

	public function toArray(): array
	{
		if ($this->canReturnSourceArrayDirectly()) {
			return $this->source;
		}

		$result = $this->to([]);
		if (! is_array($result)) {
			throw new MappingException('Mapped result is not an array.');
		}

		return $result;
	}

	public function toJson(): string
	{
		$value = $this->canReturnSourceArrayDirectly()
			? $this->source
			: $this->toArray();

		try {
			return json_encode($value, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new MappingException('Unable to encode mapped result as JSON.', 0, $exception);
		}
	}

	private function createContext(): MappingContext
	{
		$context = new MappingContext(
			$this->gateway,
			$this->sourceRepresentation,
			$this->outputRepresentation,
		);

		if ($this->mapperClass !== null) {
			$context = $context->withMapperClass($this->mapperClass, $this->arguments);
		} elseif ($this->arguments !== []) {
			$context = $context->withArguments($this->arguments);
		}

		if ($this->collection) {
			$context = $context->asCollection();
		}

		return $context;
	}

	private function canReturnSourceArrayDirectly(): bool
	{
		return is_array($this->source)
			&& $this->mapperClass === null
			&& $this->sourceRepresentation === null
			&& $this->outputRepresentation === null
			&& $this->arguments === []
			&& ! $this->collection;
	}
}
