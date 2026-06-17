<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use Closure;
use ON\Data\Mapper\Exception\DuplicateMapperComponentRegistrationException;
use ON\Data\Mapper\Exception\IncompatibleWalkerException;
use ON\Data\Mapper\Exception\IncompatibleWriterException;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Exception\MapperComponentConfigurationException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Exception\NoWalkerFoundException;
use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\Resolver\FieldResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyFieldResolver;
use ON\Data\Mapper\Walker\ArrayWalker;
use ON\Data\Mapper\Walker\ObjectWalker;
use ON\Data\Mapper\Walker\WalkerInterface;
use ON\Data\Mapper\Writer\ArrayWriter;
use ON\Data\Mapper\Writer\ObjectWriter;
use ON\Data\Mapper\Writer\WriterInterface;

final class MapperManager
{
	/**
	 * @var list<class-string<WalkerInterface>>
	 */
	private array $walkers = [];

	/**
	 * @var list<class-string<WriterInterface>>
	 */
	private array $writers = [];

	/**
	 * @var list<class-string<FieldResolverInterface>>
	 */
	private array $resolvers = [];

	/**
	 * @var array<class-string<WalkerInterface>, WalkerInterface>
	 */
	private array $walkerInstances = [];

	/**
	 * @var array<class-string<WriterInterface>, WriterInterface>
	 */
	private array $writerInstances = [];

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?Closure $constructor = null,
	) {
	}

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public static function createDefault(
		ConversionGateway $gateway,
		?Closure $constructor = null,
	): self {
		$manager = new self($gateway, $constructor);
		$manager->register(ArrayWalker::class);
		$manager->register(ObjectWalker::class);
		$manager->register(ArrayWriter::class);
		$manager->register(ObjectWriter::class);
		$manager->register(ReflectionPropertyFieldResolver::class);

		return $manager;
	}

	public function register(string $component): self
	{
		$role = $this->detectRole($component);
		$bucket = &$this->bucketFor($role);

		if (in_array($component, $bucket, true)) {
			throw new DuplicateMapperComponentRegistrationException(
				sprintf("Mapper component '%s' is already registered.", $component),
			);
		}

		$bucket[] = $component;

		return $this;
	}

	public function has(string $component): bool
	{
		return in_array($component, $this->walkers, true)
			|| in_array($component, $this->writers, true)
			|| in_array($component, $this->resolvers, true);
	}

	/**
	 * @return list<class-string<WalkerInterface>>
	 */
	public function getRegisteredWalkers(): array
	{
		return $this->walkers;
	}

	/**
	 * @return list<class-string<WriterInterface>>
	 */
	public function getRegisteredWriters(): array
	{
		return $this->writers;
	}

	/**
	 * @return list<class-string<FieldResolverInterface>>
	 */
	public function getRegisteredResolvers(): array
	{
		return $this->resolvers;
	}

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public function setConstructor(?Closure $constructor): self
	{
		if ($this->walkerInstances !== [] || $this->writerInstances !== []) {
			throw new MapperComponentConfigurationException(
				'Cannot change the mapper component constructor after reusable component instances have been created.',
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

		$walker = $this->resolveWalker($source, $context);
		$writer = $this->resolveWriter($target, $context);
		$result = $writer->prepare($target, $context);
		$runtimeContext = $context->withTarget($result);
		$coordinator = new FieldConversionCoordinator(
			$context->getGateway(),
			$this->createResolverChain($runtimeContext),
		);

		$walker->walk(
			$source,
			$runtimeContext,
			function (string|int $name, mixed $value, mixed $walkerArguments = null) use (
				$writer,
				$coordinator,
				&$result,
				$runtimeContext,
			): void {
				$fieldContext = $runtimeContext->withPathSegment((string) $name);
				$field = $coordinator->resolveField(
					$runtimeContext,
					$fieldContext->getPath(),
					$name,
					$value,
					$walkerArguments,
				);
				$converted = $coordinator->convertScalar($value, $field, $fieldContext);

				$result = $writer->write(
					$result,
					$name,
					$converted,
					$fieldContext,
					$walkerArguments,
				);
			},
		);

		return $writer->finish($result, $runtimeContext->withTarget($result));
	}

	public function clear(): void
	{
		$this->walkerInstances = [];
		$this->writerInstances = [];
	}

	public function warmUp(): void
	{
		foreach ($this->walkers as $walker) {
			$this->getWalker($walker);
		}

		foreach ($this->writers as $writer) {
			$this->getWriter($writer);
		}
	}

	/**
	 * @param class-string<WalkerInterface> $walker
	 */
	public function getWalker(string $walker): WalkerInterface
	{
		if (! isset($this->walkerInstances[$walker])) {
			$instance = $this->construct($walker);
			if (! $instance instanceof WalkerInterface) {
				throw new MapperComponentConfigurationException(
					sprintf("Constructor did not return a valid walker '%s'.", $walker),
				);
			}

			$this->walkerInstances[$walker] = $instance;
		}

		return $this->walkerInstances[$walker];
	}

	/**
	 * @param class-string<WriterInterface> $writer
	 */
	public function getWriter(string $writer): WriterInterface
	{
		if (! isset($this->writerInstances[$writer])) {
			$instance = $this->construct($writer);
			if (! $instance instanceof WriterInterface) {
				throw new MapperComponentConfigurationException(
					sprintf("Constructor did not return a valid writer '%s'.", $writer),
				);
			}

			$this->writerInstances[$writer] = $instance;
		}

		return $this->writerInstances[$writer];
	}

	/**
	 * @return list<FieldResolverInterface>
	 */
	public function createResolverChain(MappingContext $context): array
	{
		$chain = [];

		foreach ($context->getResolverClasses() as $resolverClass) {
			$chain[] = $this->constructResolver($resolverClass);
		}

		foreach ($this->resolvers as $resolverClass) {
			$chain[] = $this->constructResolver($resolverClass);
		}

		return $chain;
	}

	public function resolveWalker(
		mixed $source,
		MappingContext $context,
	): WalkerInterface {
		$walkerClass = $context->getWalkerClass();
		if ($walkerClass !== null) {
			$this->assertRoleOrThrow($walkerClass, WalkerInterface::class);
			if (! $walkerClass::canWalk($source, $context)) {
				throw new IncompatibleWalkerException(
					sprintf("Walker '%s' cannot walk the given source.", $walkerClass),
				);
			}

			return $this->has($walkerClass)
				? $this->getWalker($walkerClass)
				: $this->constructWalker($walkerClass);
		}

		foreach ($this->walkers as $candidate) {
			if ($candidate::canWalk($source, $context)) {
				return $this->getWalker($candidate);
			}
		}

		throw new NoWalkerFoundException('No registered walker can handle the requested source.');
	}

	public function resolveWriter(
		mixed $target,
		MappingContext $context,
	): WriterInterface {
		$writerClass = $context->getWriterClass();
		if ($writerClass !== null) {
			$this->assertRoleOrThrow($writerClass, WriterInterface::class);
			if (! $writerClass::canWrite($target, $context)) {
				throw new IncompatibleWriterException(
					sprintf("Writer '%s' cannot write the given target.", $writerClass),
				);
			}

			return $this->has($writerClass)
				? $this->getWriter($writerClass)
				: $this->constructWriter($writerClass);
		}

		foreach ($this->writers as $candidate) {
			if ($candidate::canWrite($target, $context)) {
				return $this->getWriter($candidate);
			}
		}

		throw new NoWriterFoundException('No registered writer can handle the requested target.');
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
		$itemContext = $context->withCollection(false);

		foreach ($source as $key => $item) {
			$results[] = $this->map(
				$item,
				$target,
				$itemContext->withPathSegment((string) $key),
			);
		}

		return $results;
	}

	private function construct(string $component): object
	{
		return $this->constructor !== null
			? ($this->constructor)($component, $this->gateway)
			: new $component();
	}

	/**
	 * @param class-string<WalkerInterface> $walkerClass
	 */
	private function constructWalker(string $walkerClass): WalkerInterface
	{
		$instance = $this->construct($walkerClass);
		if (! $instance instanceof WalkerInterface) {
			throw new MapperComponentConfigurationException(
				sprintf("Constructor did not return a valid walker '%s'.", $walkerClass),
			);
		}

		return $instance;
	}

	/**
	 * @param class-string<WriterInterface> $writerClass
	 */
	private function constructWriter(string $writerClass): WriterInterface
	{
		$instance = $this->construct($writerClass);
		if (! $instance instanceof WriterInterface) {
			throw new MapperComponentConfigurationException(
				sprintf("Constructor did not return a valid writer '%s'.", $writerClass),
			);
		}

		return $instance;
	}

	/**
	 * @param class-string<FieldResolverInterface> $resolverClass
	 */
	private function constructResolver(string $resolverClass): FieldResolverInterface
	{
		$this->assertRoleOrThrow($resolverClass, FieldResolverInterface::class);
		$instance = $this->construct($resolverClass);
		if (! $instance instanceof FieldResolverInterface) {
			throw new MapperComponentConfigurationException(
				sprintf("Constructor did not return a valid resolver '%s'.", $resolverClass),
			);
		}

		return $instance;
	}

	private function detectRole(string $component): string
	{
		if (! class_exists($component)) {
			throw new InvalidMapperComponentException(
				sprintf("Mapper component '%s' must be an existing class.", $component),
			);
		}

		$roles = [];
		if (is_a($component, WalkerInterface::class, true)) {
			$roles[] = 'walker';
		}

		if (is_a($component, WriterInterface::class, true)) {
			$roles[] = 'writer';
		}

		if (is_a($component, FieldResolverInterface::class, true)) {
			$roles[] = 'resolver';
		}

		if (count($roles) !== 1) {
			throw new InvalidMapperComponentException(
				sprintf(
					"Mapper component '%s' must implement exactly one of %s, %s, or %s.",
					$component,
					WalkerInterface::class,
					WriterInterface::class,
					FieldResolverInterface::class,
				),
			);
		}

		return $roles[0];
	}

	/**
	 * @return array<int, string>
	 */
	private function &bucketFor(string $role): array
	{
		if ($role === 'walker') {
			return $this->walkers;
		}

		if ($role === 'writer') {
			return $this->writers;
		}

		return $this->resolvers;
	}

	private function assertRoleOrThrow(string $component, string $requiredInterface): void
	{
		if (! class_exists($component) || ! is_a($component, $requiredInterface, true)) {
			throw new InvalidMapperComponentException(
				sprintf("Mapper component '%s' must implement %s.", $component, $requiredInterface),
			);
		}
	}
}
