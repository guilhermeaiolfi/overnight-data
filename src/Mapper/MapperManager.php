<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use Closure;
use ON\Data\Mapper\Exception\DuplicateMapperRegistrationException;
use ON\Data\Mapper\Exception\IncompatibleMapperException;
use ON\Data\Mapper\Exception\InvalidMapperClassException;
use ON\Data\Mapper\Exception\MapperConfigurationException;
use ON\Data\Mapper\Exception\MapperNotFoundException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Exception\NoMapperFoundException;

final class MapperManager
{
	/**
	 * @var list<class-string<MapperInterface>>
	 */
	private array $mappers = [];

	/**
	 * @var array<class-string<MapperInterface>, MapperInterface>
	 */
	private array $instances = [];

	/**
	 * @param null|Closure(
	 *     class-string<MapperInterface>,
	 *     ConversionGateway
	 * ): MapperInterface $constructor
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?Closure $constructor = null,
	) {
	}

	/**
	 * @param null|Closure(
	 *     class-string<MapperInterface>,
	 *     ConversionGateway
	 * ): MapperInterface $constructor
	 */
	public static function createDefault(
		ConversionGateway $gateway,
		?Closure $constructor = null,
	): self {
		$manager = new self($gateway, $constructor);
		$manager->register(ArrayToStdClassMapper::class);
		$manager->register(StdClassToArrayMapper::class);
		$manager->register(ArrayToObjectMapper::class);
		$manager->register(ObjectToArrayMapper::class);

		return $manager;
	}

	/**
	 * @param class-string<MapperInterface> $mapper
	 */
	public function register(string $mapper): self
	{
		$this->assertValidMapperClass($mapper);

		if ($this->has($mapper)) {
			throw new DuplicateMapperRegistrationException(
				sprintf("Mapper '%s' is already registered.", $mapper)
			);
		}

		$this->mappers[] = $mapper;

		return $this;
	}

	public function has(string $mapper): bool
	{
		return in_array($mapper, $this->mappers, true);
	}

	/**
	 * @return list<class-string<MapperInterface>>
	 */
	public function getRegisteredMappers(): array
	{
		return $this->mappers;
	}

	/**
	 * @param null|Closure(
	 *     class-string<MapperInterface>,
	 *     ConversionGateway
	 * ): MapperInterface $constructor
	 */
	public function setConstructor(?Closure $constructor): self
	{
		if ($this->instances !== []) {
			throw new MapperConfigurationException(
				'Cannot change the mapper constructor after mapper instances have been created.'
			);
		}

		$this->constructor = $constructor;

		return $this;
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): mixed {
		if ($context->isCollection()) {
			return $this->mapCollection($source, $target, $context);
		}

		$mapperClass = $context->getMapperClass();
		if ($mapperClass !== null) {
			if (! $this->has($mapperClass)) {
				throw new MapperNotFoundException(
					sprintf("Mapper '%s' is not registered.", $mapperClass)
				);
			}

			$context = $this->applyDefaultRepresentations($mapperClass, $context);

			if (! $mapperClass::canMap($source, $target, $context)) {
				throw new IncompatibleMapperException(
					sprintf("Mapper '%s' cannot map the given source and target.", $mapperClass)
				);
			}

			return $this->getMapper($mapperClass)->map($source, $target, $context);
		}

		foreach ($this->mappers as $mapperClass) {
			$candidateContext = $this->applyDefaultRepresentations($mapperClass, $context);
			if (! $mapperClass::canMap($source, $target, $candidateContext)) {
				continue;
			}

			return $this->getMapper($mapperClass)->map($source, $target, $candidateContext);
		}

		throw new NoMapperFoundException('No registered mapper can handle the requested mapping.');
	}

	/**
	 * @param class-string<MapperInterface> $mapper
	 */
	public function getMapper(string $mapper): MapperInterface
	{
		if (! $this->has($mapper)) {
			throw new MapperNotFoundException(
				sprintf("Mapper '%s' is not registered.", $mapper)
			);
		}

		if (isset($this->instances[$mapper])) {
			return $this->instances[$mapper];
		}

		$instance = $this->constructor !== null
			? ($this->constructor)($mapper, $this->gateway)
			: new $mapper($this->gateway);

		if (! $instance instanceof MapperInterface || ! $instance instanceof $mapper) {
			throw new MapperConfigurationException(
				sprintf("Constructor did not return a valid '%s' instance.", $mapper)
			);
		}

		return $this->instances[$mapper] = $instance;
	}

	public function warmUp(): void
	{
		foreach ($this->mappers as $mapper) {
			$this->getMapper($mapper);
		}
	}

	public function clear(): void
	{
		$this->instances = [];
	}

	private function assertValidMapperClass(string $mapper): void
	{
		if (! class_exists($mapper) || ! is_a($mapper, MapperInterface::class, true)) {
			throw new InvalidMapperClassException(
				sprintf("Mapper '%s' must be a valid class implementing %s.", $mapper, MapperInterface::class)
			);
		}
	}

	private function applyDefaultRepresentations(
		string $mapperClass,
		MappingContext $context,
	): MappingContext {
		$defaults = $mapperClass::defaultRepresentations();

		if ($context->getSourceRepresentation() === null && isset($defaults['from'])) {
			$context = $context->withSourceRepresentation($defaults['from']);
		}

		if ($context->getOutputRepresentation() === null && isset($defaults['as'])) {
			$context = $context->withOutputRepresentation($defaults['as']);
		}

		return $context;
	}

	private function mapCollection(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): array {
		if (! is_iterable($source)) {
			throw new MappingException('Collection mapping requires an iterable source.');
		}

		$results = [];
		$childContext = $context->withCollection(false);

		foreach ($source as $key => $item) {
			$results[] = $this->map(
				$item,
				$target,
				$childContext->withPathSegment((string) $key),
			);
		}

		return $results;
	}
}
