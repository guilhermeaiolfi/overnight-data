<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use Closure;
use ON\Data\Mapper\Exception\DuplicateMapperComponentRegistrationException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\IncompatibleMapperException;
use ON\Data\Mapper\Exception\IncompatibleWriterException;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Exception\MapperComponentConfigurationException;
use ON\Data\Mapper\Exception\NoMapperFoundException;
use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\Field\BackedEnumFieldType;
use ON\Data\Mapper\Field\BigIntFieldType;
use ON\Data\Mapper\Field\BoolFieldType;
use ON\Data\Mapper\Field\DateFieldType;
use ON\Data\Mapper\Field\DateTimeFieldType;
use ON\Data\Mapper\Field\DateTimeWireCodec;
use ON\Data\Mapper\Field\DecimalFieldType;
use ON\Data\Mapper\Field\FloatFieldType;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\JsonFieldType;
use ON\Data\Mapper\Field\PassthroughFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\Data\Mapper\Field\UrlFieldType;
use ON\Data\Mapper\Mapper\ArrayMapper;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\Mapper\ObjectMapper;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\DefinitionNodeResolver;
use ON\Data\Mapper\Resolver\FieldMapNodeResolver;
use ON\Data\Mapper\Resolver\GenericNodeResolver;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Resolver\PassthroughNodeResolver;
use ON\Data\Mapper\Resolver\ReflectionPropertyNodeResolver;
use ON\Data\Mapper\Writer\ArrayWriter;
use ON\Data\Mapper\Writer\ObjectWriter;
use ON\Data\Mapper\Writer\WriterInterface;
use stdClass;

final class MapperManager
{
	private const DEFAULT_MAPPERS = [ArrayMapper::class, ObjectMapper::class];

	private const DEFAULT_WRITERS = [ArrayWriter::class, ObjectWriter::class];

	private const DEFAULT_RESOLVERS = [
		FieldMapNodeResolver::class,
		DefinitionNodeResolver::class,
		ReflectionPropertyNodeResolver::class,
		GenericNodeResolver::class,
		PassthroughNodeResolver::class,
	];

	/**
	 * @var list<class-string<MapperInterface>>
	 */
	private array $mappers = [];

	/**
	 * @var list<class-string<WriterInterface>>
	 */
	private array $writers = [];

	/**
	 * @var list<class-string<NodeResolverInterface>>
	 */
	private array $resolvers = [];

	/**
	 * @var array<class-string<MapperInterface>, MapperInterface>
	 */
	private array $mapperInstances = [];

	/**
	 * @var array<class-string<WriterInterface>, WriterInterface>
	 */
	private array $writerInstances = [];

	/**
	 * @var array<class-string, true>
	 */
	private array $registeredComponents = [];

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private ?Closure $constructor = null,
		private readonly FieldTypeRegistry $fieldTypes = new FieldTypeRegistry(),
	) {
	}

	public function __clone()
	{
		$this->fieldTypes = clone $this->fieldTypes;
	}

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public static function createDefault(
		ConversionGateway $gateway,
		?Closure $constructor = null,
	): self {
		$manager = $gateway->getMapperManager();
		if ($constructor !== null) {
			$manager->setConstructor($constructor);
		}

		foreach ([
			...self::DEFAULT_MAPPERS,
			...self::DEFAULT_WRITERS,
			...self::DEFAULT_RESOLVERS,
			StringFieldType::class,
			PassthroughFieldType::class,
			BoolFieldType::class,
			BackedEnumFieldType::class,
			IntFieldType::class,
			BigIntFieldType::class,
			DecimalFieldType::class,
			FloatFieldType::class,
			JsonFieldType::class,
			UrlFieldType::class,
			DateFieldType::class,
			DateTimeFieldType::class,
			DateTimeWireCodec::class,
		] as $component) {
			if (! $manager->has($component)) {
				$manager->addComponent($component, append: true, overwrite: false);
			}
		}

		return $manager;
	}

	public function register(string $component): self
	{
		$this->addComponent($component, append: true);

		return $this;
	}

	public function prepend(string $component): self
	{
		$this->addComponent($component, append: false);

		return $this;
	}

	public function has(string $component): bool
	{
		return isset($this->registeredComponents[$component]);
	}

	/**
	 * @return list<class-string<MapperInterface>>
	 */
	public function getRegisteredMappers(): array
	{
		return $this->mappers;
	}

	/**
	 * @return list<class-string<WriterInterface>>
	 */
	public function getRegisteredWriters(): array
	{
		return $this->writers;
	}

	/**
	 * @return list<class-string<NodeResolverInterface>>
	 */
	public function getRegisteredResolvers(): array
	{
		return $this->resolvers;
	}

	/**
	 * @return array<string, class-string<FieldTypeInterface>>
	 */
	public function getRegisteredFieldTypes(): array
	{
		return $this->fieldTypes->getRegisteredFieldTypes();
	}

	/**
	 * @return array<
	 *     class-string<RepresentationInterface>,
	 *     array<
	 *         class-string<FieldTypeInterface>,
	 *         class-string<FieldTypeCodecInterface>
	 *     >
	 * >
	 */
	public function getRegisteredFieldTypeCodecs(): array
	{
		return $this->fieldTypes->getRegisteredFieldTypeCodecs();
	}

	/**
	 * @param null|Closure(string, ConversionGateway): object $constructor
	 */
	public function setConstructor(?Closure $constructor): self
	{
		if ($this->mapperInstances !== [] || $this->writerInstances !== []) {
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
		MappingOptions $options,
	): mixed {
		$mapper = null;
		if (
			$options->isShallow()
			&& $source instanceof stdClass
			&& $source::class === stdClass::class
			&& $target === []
			&& $options->getMapperClass() === null
			&& $options->getWriterClass() === null
			&& $options->getResolverClasses() === []
			&& $options->getFieldMap() === null
			&& $options->getArguments() === []
			&& ! $options->isCollection()
			&& $options->getSourceRepresentation() === null
			&& $options->getOutputRepresentation() === null
			&& $this->writers === self::DEFAULT_WRITERS
			&& $this->resolvers === self::DEFAULT_RESOLVERS
			&& $this->constructor === null
		) {
			$mapper = $this->resolveMapper($source, $options);
			if ($mapper instanceof ObjectMapper) {
				return get_object_vars($source);
			}
		}

		$runtime = new MappingRuntime(
			mapperManager: $this,
		);

		return $runtime->mapNode(
			MappingNode::root(
				source: $source,
				target: $target,
				options: $options,
			),
			mapper: $mapper,
		);
	}

	public function clear(): void
	{
		$this->mapperInstances = [];
		$this->writerInstances = [];
		$this->fieldTypes->clearCache();
		$this->gateway->clearRoutes();
	}

	public function warmUp(): void
	{
		foreach ($this->mappers as $mapper) {
			$this->getMapper($mapper);
		}

		foreach ($this->writers as $writer) {
			$this->getWriter($writer);
		}
	}

	/**
	 * @param class-string<MapperInterface> $mapper
	 */
	public function getMapper(string $mapper): MapperInterface
	{
		if (! isset($this->mapperInstances[$mapper])) {
			$this->mapperInstances[$mapper] = $this->constructTypedComponent($mapper, MapperInterface::class, 'mapper');
		}

		return $this->mapperInstances[$mapper];
	}

	/**
	 * @param class-string<WriterInterface> $writer
	 */
	public function getWriter(string $writer): WriterInterface
	{
		if (! isset($this->writerInstances[$writer])) {
			$this->writerInstances[$writer] = $this->constructTypedComponent($writer, WriterInterface::class, 'writer');
		}

		return $this->writerInstances[$writer];
	}

	/**
	 * @return list<NodeResolverInterface>
	 */
	public function createResolverChain(MappingOptions $options): array
	{
		$chain = [];

		foreach ($options->getResolverClasses() as $resolverClass) {
			$chain[] = $this->constructResolver($resolverClass);
		}

		foreach ($this->resolvers as $resolverClass) {
			$chain[] = $this->constructResolver($resolverClass);
		}

		return $chain;
	}

	public function getGateway(): ConversionGateway
	{
		return $this->gateway;
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolveFieldType(LeafNodeResolutionInterface $field): ?string
	{
		return $this->fieldTypes->resolveFieldType($field);
	}

	/**
	 * @return class-string<FieldTypeInterface>
	 */
	public function getFieldType(string $name): string
	{
		$resolved = $this->resolveFieldType(LeafNodeResolution::named($name, $name));
		if ($resolved === null) {
			throw new FieldTypeNotFoundException(sprintf("FieldType '%s' is not registered.", $name));
		}

		return $resolved;
	}

	/**
	 * @param class-string<FieldTypeInterface> $fieldType
	 * @param class-string<RepresentationInterface> $representation
	 *
	 * @return class-string<FieldTypeCodecInterface>|null
	 */
	public function resolveFieldTypeCodec(string $fieldType, string $representation): ?string
	{
		return $this->fieldTypes->resolveFieldTypeCodec($fieldType, $representation);
	}

	public function canMap(mixed $source, ?MappingOptions $options = null): bool
	{
		return $this->findMapperClass($source, $options ?? new MappingOptions($this->gateway)) !== null;
	}

	public function canWrite(mixed $target, ?MappingOptions $options = null): bool
	{
		return $this->findWriterClass($target, $options ?? new MappingOptions($this->gateway)) !== null;
	}

	public function supports(mixed $source, mixed $target, ?MappingOptions $options = null): bool
	{
		return $this->canMap($source, $options) && $this->canWrite($target, $options);
	}

	public function resolveMapper(
		mixed $source,
		MappingOptions $options,
	): MapperInterface {
		$mapperClass = $this->findMapperClass($source, $options);
		if ($mapperClass !== null) {
			return $this->has($mapperClass)
				? $this->getMapper($mapperClass)
				: $this->constructMapper($mapperClass);
		}

		$override = $options->getMapperClass();
		if ($override !== null) {
			throw new IncompatibleMapperException(
				sprintf("Mapper '%s' cannot map the given source.", $override),
			);
		}

		throw new NoMapperFoundException('No registered mapper can handle the requested source.');
	}

	/**
	 * @return class-string<MapperInterface>|null
	 */
	private function findMapperClass(mixed $source, MappingOptions $options): ?string
	{
		$override = $options->getMapperClass();
		if ($override !== null) {
			$this->assertRoleOrThrow($override, MapperInterface::class);

			return $override::canMap($source, $options) ? $override : null;
		}

		foreach ($this->mappers as $candidate) {
			if ($candidate::canMap($source, $options)) {
				return $candidate;
			}
		}

		return null;
	}

	public function resolveWriter(
		mixed $target,
		MappingOptions $options,
	): WriterInterface {
		$writerClass = $this->findWriterClass($target, $options);
		if ($writerClass !== null) {
			return $this->has($writerClass)
				? $this->getWriter($writerClass)
				: $this->constructWriter($writerClass);
		}

		$override = $options->getWriterClass();
		if ($override !== null) {
			throw new IncompatibleWriterException(
				sprintf("Writer '%s' cannot write the given target.", $override),
			);
		}

		throw new NoWriterFoundException(
			sprintf(
				'No registered writer can handle the requested target %s.',
				$this->describeTarget($target),
			),
		);
	}

	/**
	 * @return class-string<WriterInterface>|null
	 */
	private function findWriterClass(mixed $target, MappingOptions $options): ?string
	{
		$override = $options->getWriterClass();
		if ($override !== null) {
			$this->assertRoleOrThrow($override, WriterInterface::class);

			return $override::canWrite($target, $options) ? $override : null;
		}

		foreach ($this->writers as $candidate) {
			if ($candidate::canWrite($target, $options)) {
				return $candidate;
			}
		}

		return null;
	}

	private function construct(string $component): object
	{
		return $this->constructor !== null
			? ($this->constructor)($component, $this->gateway)
			: new $component();
	}

	/**
	 * @param class-string<MapperInterface> $mapperClass
	 */
	private function constructMapper(string $mapperClass): MapperInterface
	{
		return $this->constructTypedComponent($mapperClass, MapperInterface::class, 'mapper');
	}

	/**
	 * @param class-string<WriterInterface> $writerClass
	 */
	private function constructWriter(string $writerClass): WriterInterface
	{
		return $this->constructTypedComponent($writerClass, WriterInterface::class, 'writer');
	}

	/**
	 * @param class-string<NodeResolverInterface> $resolverClass
	 */
	private function constructResolver(string $resolverClass): NodeResolverInterface
	{
		$this->assertRoleOrThrow($resolverClass, NodeResolverInterface::class);

		return $this->constructTypedComponent($resolverClass, NodeResolverInterface::class, 'resolver');
	}

	private function addComponent(string $component, bool $append, bool $overwrite = true): void
	{
		$role = $this->detectRole($component);

		if ($role === 'fieldType') {
			if (! $append) {
				throw new InvalidMapperComponentException(
					sprintf("Mapper component '%s' cannot be prepended because field type registrations are keyed, not ordered.", $component),
				);
			}

			$this->assertNotRegistered($component);
			$this->fieldTypes->registerFieldType($component, $overwrite);
			$this->gateway->clearRoutes();
			$this->registeredComponents[$component] = true;

			return;
		}

		if ($role === 'codec') {
			if (! $append) {
				throw new InvalidMapperComponentException(
					sprintf("Mapper component '%s' cannot be prepended because codec registrations are keyed, not ordered.", $component),
				);
			}

			$this->assertNotRegistered($component);
			$this->fieldTypes->registerFieldTypeCodec($component, $overwrite);
			$this->gateway->clearRoutes();
			$this->registeredComponents[$component] = true;

			return;
		}

		$bucket = &$this->bucketFor($role);
		$this->assertNotRegistered($component);

		if ($append) {
			$bucket[] = $component;
		} else {
			array_unshift($bucket, $component);
		}

		$this->registeredComponents[$component] = true;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $requestedClass
	 * @param class-string $expectedInterface
	 *
	 * @return T
	 */
	private function constructTypedComponent(
		string $requestedClass,
		string $expectedInterface,
		string $role,
	): object {
		$instance = $this->construct($requestedClass);

		if (! $instance instanceof $expectedInterface || ! $instance instanceof $requestedClass) {
			throw new MapperComponentConfigurationException(
				sprintf(
					"Constructor returned %s while building %s '%s'; expected an instance of %s.",
					$instance::class,
					$role,
					$requestedClass,
					$requestedClass,
				),
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
		if (is_a($component, MapperInterface::class, true)) {
			$roles[] = 'mapper';
		}

		if (is_a($component, WriterInterface::class, true)) {
			$roles[] = 'writer';
		}

		if (is_a($component, NodeResolverInterface::class, true)) {
			$roles[] = 'resolver';
		}

		if (is_a($component, FieldTypeInterface::class, true)) {
			$roles[] = 'fieldType';
		}

		if (is_a($component, FieldTypeCodecInterface::class, true)) {
			$roles[] = 'codec';
		}

		if (count($roles) !== 1) {
			throw new InvalidMapperComponentException(
				sprintf(
					"Mapper component '%s' must implement exactly one of %s, %s, %s, %s, or %s.",
					$component,
					MapperInterface::class,
					WriterInterface::class,
					NodeResolverInterface::class,
					FieldTypeInterface::class,
					FieldTypeCodecInterface::class,
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
		if ($role === 'mapper') {
			return $this->mappers;
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

	private function assertNotRegistered(string $component): void
	{
		if (isset($this->registeredComponents[$component])) {
			throw new DuplicateMapperComponentRegistrationException(
				sprintf("Mapper component '%s' is already registered.", $component),
			);
		}
	}

	private function describeTarget(mixed $target): string
	{
		if (is_object($target)) {
			return sprintf("object of type '%s'", $target::class);
		}

		if (is_string($target)) {
			return sprintf("class-string '%s'", $target);
		}

		return sprintf("value of type '%s'", gettype($target));
	}
}
