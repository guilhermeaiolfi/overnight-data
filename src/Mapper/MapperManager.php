<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use Closure;
use ON\Data\Mapper\Exception\DuplicateMapperComponentRegistrationException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\IncompatibleWalkerException;
use ON\Data\Mapper\Exception\IncompatibleWriterException;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Exception\MapperComponentConfigurationException;
use ON\Data\Mapper\Exception\NoWalkerFoundException;
use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\Field\BoolFieldType;
use ON\Data\Mapper\Field\FloatFieldType;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\PassthroughFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolver\DefinitionFieldResolver;
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
		$manager->register(DefinitionFieldResolver::class);
		$manager->register(ReflectionPropertyFieldResolver::class);
		$manager->register(StringFieldType::class);
		$manager->register(PassthroughFieldType::class);
		$manager->register(BoolFieldType::class);
		$manager->register(IntFieldType::class);
		$manager->register(FloatFieldType::class);

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
		return in_array($component, $this->walkers, true)
			|| in_array($component, $this->writers, true)
			|| in_array($component, $this->resolvers, true)
			|| in_array($component, $this->fieldTypes, true)
			|| $this->hasCodecClass($component);
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
		return $this->mapNode(MappingNode::root($source, $target, $context));
	}

	public function mapNode(MappingNode $node): mixed
	{
		$node->assertNoObjectCycle();

		$walker = $this->resolveWalker($node->getValue(), $node->getContext());

		return $walker->walk($node, $this);
	}

	public function clear(): void
	{
		$this->walkerInstances = [];
		$this->writerInstances = [];
		$this->resolvedFieldTypeCodecs = [];
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
			$this->walkerInstances[$walker] = $this->constructTypedComponent($walker, WalkerInterface::class, 'walker');
		}

		return $this->walkerInstances[$walker];
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

	public function createFieldConversionCoordinator(MappingContext $context): FieldConversionCoordinator
	{
		return new FieldConversionCoordinator(
			$context->getGateway(),
			$this->createResolverChain($context),
		);
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolveFieldType(FieldContext $field): ?string
	{
		$type = $field->getType();

		if (is_a($type, FieldTypeInterface::class, true)) {
			/** @var class-string<FieldTypeInterface> $type */
			return $type;
		}

		if (isset($this->fieldTypes[$type])) {
			return $this->fieldTypes[$type];
		}

		return $this->fieldTypes[strtolower($type)] ?? null;
	}

	/**
	 * @return class-string<FieldTypeInterface>
	 */
	public function getFieldType(string $name): string
	{
		$resolved = $this->resolveFieldType(FieldContext::named($name, $name));
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
	 * @param class-string<WalkerInterface> $walkerClass
	 */
	private function constructWalker(string $walkerClass): WalkerInterface
	{
		return $this->constructTypedComponent($walkerClass, WalkerInterface::class, 'walker');
	}

	/**
	 * @param class-string<WriterInterface> $writerClass
	 */
	private function constructWriter(string $writerClass): WriterInterface
	{
		return $this->constructTypedComponent($writerClass, WriterInterface::class, 'writer');
	}

	/**
	 * @param class-string<FieldResolverInterface> $resolverClass
	 */
	private function constructResolver(string $resolverClass): FieldResolverInterface
	{
		$this->assertRoleOrThrow($resolverClass, FieldResolverInterface::class);

		return $this->constructTypedComponent($resolverClass, FieldResolverInterface::class, 'resolver');
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

			if ($this->has($component)) {
				throw new DuplicateMapperComponentRegistrationException(
					sprintf("Mapper component '%s' is already registered.", $component),
				);
			}

			$this->registerFieldType($component);

			return;
		}

		if ($role === 'codec') {
			if (! $append) {
				throw new InvalidMapperComponentException(
					sprintf("Mapper component '%s' cannot be prepended because codec registrations are keyed, not ordered.", $component),
				);
			}

			if ($this->has($component)) {
				throw new DuplicateMapperComponentRegistrationException(
					sprintf("Mapper component '%s' is already registered.", $component),
				);
			}

			$this->registerFieldTypeCodec($component);

			return;
		}

		$bucket = &$this->bucketFor($role);
		if (in_array($component, $bucket, true)) {
			throw new DuplicateMapperComponentRegistrationException(
				sprintf("Mapper component '%s' is already registered.", $component),
			);
		}

		if ($append) {
			$bucket[] = $component;

			return;
		}

		array_unshift($bucket, $component);
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
		if (is_a($component, WalkerInterface::class, true)) {
			$roles[] = 'walker';
		}

		if (is_a($component, WriterInterface::class, true)) {
			$roles[] = 'writer';
		}

		if (is_a($component, FieldResolverInterface::class, true)) {
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
					WalkerInterface::class,
					WriterInterface::class,
					FieldResolverInterface::class,
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

			$this->fieldTypes[$name] = $fieldType;
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

	private function hasCodecClass(string $component): bool
	{
		foreach ($this->fieldTypeCodecs as $codecsByFieldType) {
			if (in_array($component, $codecsByFieldType, true)) {
				return true;
			}
		}

		return false;
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
