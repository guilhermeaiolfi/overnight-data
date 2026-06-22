<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use BackedEnum;
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

final class MapperManager
{
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
	 * @var array<string, class-string<FieldTypeInterface>>
	 */
	private array $fieldTypes = [];

	/**
	 * @var array<
	 *     class-string<RepresentationInterface>,
	 *     array<
	 *         class-string<FieldTypeInterface>,
	 *         class-string<FieldTypeCodecInterface>
	 *     >
	 * >
	 */
	private array $fieldTypeCodecs = [];

	/**
	 * @var array<
	 *     class-string<FieldTypeInterface>,
	 *     array<class-string<RepresentationInterface>, class-string<FieldTypeCodecInterface>|null>
	 * >
	 */
	private array $resolvedFieldTypeCodecs = [];

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
		$manager->register(ArrayMapper::class);
		$manager->register(ObjectMapper::class);
		$manager->register(ArrayWriter::class);
		$manager->register(ObjectWriter::class);
		$manager->register(FieldMapNodeResolver::class);
		$manager->register(DefinitionNodeResolver::class);
		$manager->register(ReflectionPropertyNodeResolver::class);
		$manager->register(GenericNodeResolver::class);
		$manager->register(PassthroughNodeResolver::class);
		$manager->register(StringFieldType::class);
		$manager->register(PassthroughFieldType::class);
		$manager->register(BoolFieldType::class);
		$manager->register(BackedEnumFieldType::class);
		$manager->register(IntFieldType::class);
		$manager->register(BigIntFieldType::class);
		$manager->register(DecimalFieldType::class);
		$manager->register(FloatFieldType::class);
		$manager->register(JsonFieldType::class);
		$manager->register(UrlFieldType::class);
		$manager->register(DateFieldType::class);
		$manager->register(DateTimeFieldType::class);
		$manager->register(DateTimeWireCodec::class);

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
		return $this->fieldTypes;
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
		return $this->fieldTypeCodecs;
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
		MappingContext $context,
	): mixed {
		return (new MappingRuntime(
			mapperManager: $this,
			mappingNode: MappingNode::root(
				source: $source,
				target: $target,
				context: $context,
			),
		))->map();
	}

	public function clear(): void
	{
		$this->mapperInstances = [];
		$this->writerInstances = [];
		$this->resolvedFieldTypeCodecs = [];
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

	public function createFieldConversionCoordinator(): FieldConversionCoordinator
	{
		return new FieldConversionCoordinator($this->gateway);
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolveFieldType(LeafNodeResolutionInterface $field): ?string
	{
		$type = $field->getType();
		if ($type === null) {
			return null;
		}

		if (is_a($type, FieldTypeInterface::class, true)) {
			/** @var class-string<FieldTypeInterface> $type */
			return $type;
		}

		if (enum_exists($type) && is_a($type, BackedEnum::class, true)) {
			return BackedEnumFieldType::class;
		}

		return $this->fieldTypes[strtolower($type)] ?? null;
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
		if (isset($this->resolvedFieldTypeCodecs[$fieldType]) && array_key_exists($representation, $this->resolvedFieldTypeCodecs[$fieldType])) {
			return $this->resolvedFieldTypeCodecs[$fieldType][$representation];
		}

		$resolved = null;
		$current = $representation;

		do {
			$codec = $this->fieldTypeCodecs[$current][$fieldType] ?? null;
			if ($codec !== null) {
				$resolved = $codec;

				break;
			}

			$parent = get_parent_class($current);
			if (! is_string($parent) || ! is_a($parent, RepresentationInterface::class, true)) {
				break;
			}

			$current = $parent;
		} while (true);

		$this->resolvedFieldTypeCodecs[$fieldType][$representation] = $resolved;

		return $resolved;
	}

	public function resolveMapper(
		mixed $source,
		MappingContext $context,
	): MapperInterface {
		$mapperClass = $context->getMapperClass();
		if ($mapperClass !== null) {
			$this->assertRoleOrThrow($mapperClass, MapperInterface::class);
			if (! $mapperClass::canMap($source, $context)) {
				throw new IncompatibleMapperException(
					sprintf("Mapper '%s' cannot map the given source.", $mapperClass),
				);
			}

			return $this->has($mapperClass)
				? $this->getMapper($mapperClass)
				: $this->constructMapper($mapperClass);
		}

		foreach ($this->mappers as $candidate) {
			if ($candidate::canMap($source, $context)) {
				return $this->getMapper($candidate);
			}
		}

		throw new NoMapperFoundException('No registered mapper can handle the requested source.');
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

		throw new NoWriterFoundException(
			sprintf(
				'No registered writer can handle the requested target %s.',
				$this->describeTarget($target),
			),
		);
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

	private function addComponent(string $component, bool $append): void
	{
		$role = $this->detectRole($component);

		if ($role === 'fieldType') {
			if (! $append) {
				throw new InvalidMapperComponentException(
					sprintf("Mapper component '%s' cannot be prepended because field type registrations are keyed, not ordered.", $component),
				);
			}

			$this->assertNotRegistered($component);
			$this->registerFieldType($component);
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
			$this->registerFieldTypeCodec($component);
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

	/**
	 * @param class-string<FieldTypeInterface> $fieldType
	 */
	private function registerFieldType(string $fieldType): void
	{
		$names = $fieldType::getNames();
		if ($names === []) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType '%s' must declare at least one registration name.", $fieldType),
			);
		}

		foreach ($names as $name) {
			if (! is_string($name) || $name === '') {
				throw new InvalidMapperComponentException(
					sprintf("FieldType '%s' must declare only non-empty string names.", $fieldType),
				);
			}
		}

		foreach ($names as $name) {
			$this->fieldTypes[strtolower($name)] = $fieldType;
		}
	}

	/**
	 * @param class-string<FieldTypeCodecInterface> $codec
	 */
	private function registerFieldTypeCodec(string $codec): void
	{
		$fieldType = $codec::getFieldType();
		if (! class_exists($fieldType) || ! is_a($fieldType, FieldTypeInterface::class, true)) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType codec '%s' must reference a valid %s class.", $codec, FieldTypeInterface::class),
			);
		}

		$representation = $codec::getRepresentation();
		if (! class_exists($representation) || ! is_a($representation, RepresentationInterface::class, true)) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType codec '%s' must reference a valid %s class.", $codec, RepresentationInterface::class),
			);
		}

		/** @var class-string<FieldTypeInterface> $fieldType */
		/** @var class-string<RepresentationInterface> $representation */
		$this->fieldTypeCodecs[$representation][$fieldType] = $codec;
		$this->resolvedFieldTypeCodecs = [];
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
