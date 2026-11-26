<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use JsonException;
use ON\Data\Mapper\Exception\InvalidMapTargetException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\Policy\UnknownKeyPolicyInterface;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MapBuilder
{
	private MappingOptions $options;

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
		ConversionGateway $gateway,
		?string $sourceRepresentation = null,
		?string $outputRepresentation = null,
		?string $mapperClass = null,
		?string $writerClass = null,
		array $resolverClasses = [],
		array $arguments = [],
		?FieldMap $fieldMap = null,
		bool $collection = false,
		bool $shallow = false,
	) {
		$this->options = new MappingOptions(
			$gateway,
			$sourceRepresentation,
			$outputRepresentation,
			$mapperClass,
			$writerClass,
			$resolverClasses,
			$arguments,
			$fieldMap,
			$collection,
			$shallow,
		);
	}

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	public function from(string $representation): self
	{
		return $this->withOptions($this->options->withSourceRepresentation($representation));
	}

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	public function as(string $representation): self
	{
		return $this->withOptions($this->options->withOutputRepresentation($representation));
	}

	/**
	 * @param class-string<MapperInterface> $mapper
	 */
	public function mapper(string $mapper): self
	{
		return $this->withOptions($this->options->withMapperClass($mapper));
	}

	/**
	 * @param class-string<WriterInterface> $writer
	 */
	public function writer(string $writer): self
	{
		return $this->withOptions($this->options->withWriterClass($writer));
	}

	/**
	 * @param class-string<NodeResolverInterface> $resolver
	 */
	public function resolver(string $resolver): self
	{
		return $this->withOptions($this->options->withAddedResolverClass($resolver));
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function args(mixed ...$arguments): self
	{
		return $this->withOptions($this->options->withArguments($arguments));
	}

	public function fieldMap(FieldMap $fieldMap): self
	{
		return $this->withOptions($this->options->withFieldMap($fieldMap));
	}

	public function collection(): self
	{
		return $this->withOptions($this->options->asCollection());
	}

	public function shallow(bool $shallow = true): self
	{
		return $this->withOptions($this->options->withShallow($shallow));
	}

	/** @param class-string<UnknownKeyPolicyInterface>|UnknownKeyPolicyInterface|null $policy */
	public function unknownKeyPolicy(string|UnknownKeyPolicyInterface|null $policy): self
	{
		if (is_string($policy)) {
			$policy = $this->options->getGateway()->getMapperManager()->createUnknownKeyPolicy($policy);
		}

		return $this->withOptions($this->options->withUnknownKeyPolicy($policy));
	}

	public function to(mixed $target): mixed
	{
		if (is_string($target) && class_exists($target) && is_a($target, RepresentationInterface::class, true)) {
			throw new InvalidMapTargetException(
				sprintf("'%s' is a representation class. Call as() to configure the output representation.", $target)
			);
		}

		return $this->options->getGateway()
			->getMapperManager()
			->map($this->source, $target, $this->options);
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

	private function withOptions(MappingOptions $options): self
	{
		$clone = clone $this;
		$clone->options = $options;

		return $clone;
	}
}
