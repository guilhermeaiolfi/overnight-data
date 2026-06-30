<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use LogicException;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Support\ObjectPropertyMatcher;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use stdClass;
use Throwable;

final class ObjectWriter implements WriterInterface
{
	/**
	 * @var array<class-string, ReflectionClass<object>>
	 */
	private array $reflections = [];

	/**
	 * @var array<class-string, ObjectPropertyMatcher>
	 */
	private array $propertyMatchers = [];

	/**
	 * @var array<class-string, true>
	 */
	private array $validatedTargets = [];

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		if ($target instanceof stdClass) {
			return true;
		}

		if (! is_string($target)) {
			return false;
		}

		if ($target === stdClass::class) {
			return true;
		}

		if (! class_exists($target)) {
			return false;
		}

		$reflection = new ReflectionClass($target);

		return self::supportsReflectionTarget($reflection);
	}

	public function createState(
		MappingNode $node,
	): WriterStateInterface {
		$state = new ObjectWriterState();
		$target = $node->getTarget();

		if ($target instanceof stdClass) {
			$state->target = clone $target;

			return $state;
		}

		if ($target === stdClass::class) {
			$state->target = new stdClass();

			return $state;
		}

		$reflection = $this->getReflection($target);
		$this->assertSupportedTargetOnce($reflection);

		if ($this->shouldDelayTargetCreation($reflection)) {
			return $state;
		}

		try {
			$state->target = $reflection->newInstanceWithoutConstructor();

			return $state;
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' without calling its constructor.", $reflection->getName()),
				0,
				$exception,
			);
		}
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		$objectState = $this->requireState($state);
		$objectState->values[$name] = $value;
		$objectState->writes[] = [
			'name' => $name,
			'value' => $value,
			'node' => $node,
		];

		if ($objectState->target === null) {
			return;
		}

		$this->applyWrite(
			target: $objectState->target,
			name: $name,
			value: $value,
			node: $node,
		);
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): object {
		$objectState = $this->requireState($state);
		if ($objectState->target !== null) {
			return $objectState->target;
		}

		$target = $node->getTarget();
		if (! is_string($target) || $target === stdClass::class) {
			throw new MappingException('Unable to resolve object result for the requested target.');
		}

		$reflection = $this->getReflection($target);
		$this->assertSupportedTargetOnce($reflection);
		[$object, $consumed] = $this->instantiateUsingResolvedValues($reflection, $objectState->values);

		foreach ($objectState->writes as $write) {
			if (isset($consumed[(string) $write['name']])) {
				continue;
			}

			$this->applyWrite(
				target: $object,
				name: $write['name'],
				value: $write['value'],
				node: $write['node'],
			);
		}

		$objectState->target = $object;

		return $object;
	}

	/**
	 * @return class-string
	 */
	private function getTargetClass(object|string $target): string
	{
		return is_object($target) ? $target::class : $target;
	}

	/**
	 * @return ReflectionClass<object>
	 */
	private function getReflection(object|string $target): ReflectionClass
	{
		$class = $this->getTargetClass($target);

		return $this->reflections[$class] ??= new ReflectionClass($class);
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function assertSupportedTargetOnce(ReflectionClass $reflection): void
	{
		$class = $reflection->getName();
		if (isset($this->validatedTargets[$class])) {
			return;
		}

		$this->assertSupportedTarget($reflection);
		$this->validatedTargets[$class] = true;
	}

	private function getPropertyMatcher(object $target): ObjectPropertyMatcher
	{
		$class = $target::class;

		return $this->propertyMatchers[$class]
			??= new ObjectPropertyMatcher($this->getReflection($target));
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function assertSupportedTarget(ReflectionClass $reflection): void
	{
		$class = $reflection->getName();

		if (self::supportsReflectionTarget($reflection)) {
			return;
		}

		if ($reflection->isInterface()) {
			throw new MappingException(sprintf("Cannot map to interface target '%s'.", $class));
		}

		if ($reflection->isAbstract()) {
			throw new MappingException(sprintf("Cannot map to abstract target '%s'.", $class));
		}

		if ($reflection->isEnum()) {
			throw new MappingException(sprintf("Cannot map to enum target '%s'.", $class));
		}

		if (is_a($class, RepresentationInterface::class, true)) {
			throw new MappingException(sprintf("Cannot map to representation target '%s'.", $class));
		}
	}

	private function requireState(WriterStateInterface $state): ObjectWriterState
	{
		if ($state instanceof ObjectWriterState) {
			return $state;
		}

		throw new LogicException('ObjectWriter requires ObjectWriterState.');
	}

	private static function supportsReflectionTarget(ReflectionClass $reflection): bool
	{
		$class = $reflection->getName();

		if ($reflection->isInterface() || $reflection->isAbstract() || $reflection->isEnum()) {
			return false;
		}

		if (is_a($class, RepresentationInterface::class, true)) {
			return false;
		}

		return true;
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function shouldDelayTargetCreation(ReflectionClass $reflection): bool
	{
		$constructor = $reflection->getConstructor();
		if (! $constructor instanceof ReflectionMethod) {
			return false;
		}

		return $constructor->getNumberOfParameters() > 0
			|| $this->isReadonlyTarget($reflection);
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function isReadonlyTarget(ReflectionClass $reflection): bool
	{
		if (method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly()) {
			return true;
		}

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (! $property->isStatic() && $property->isReadOnly()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string|int, mixed> $valuesByName
	 *
	 * @return array{0: object, 1: array<string, true>}
	 */
	private function instantiateUsingResolvedValues(
		ReflectionClass $reflection,
		array $valuesByName,
	): array {
		$constructor = $reflection->getConstructor();
		if (! $constructor instanceof ReflectionMethod) {
			try {
				return [$reflection->newInstanceWithoutConstructor(), []];
			} catch (Throwable $exception) {
				throw new MappingException(
					sprintf("Unable to instantiate '%s' without calling its constructor.", $reflection->getName()),
					0,
					$exception,
				);
			}
		}

		$arguments = [];
		$consumed = [];

		foreach ($constructor->getParameters() as $parameter) {
			$name = $parameter->getName();
			if (array_key_exists($name, $valuesByName)) {
				$arguments[] = $valuesByName[$name];
				$consumed[$name] = true;

				continue;
			}

			if ($parameter->isDefaultValueAvailable()) {
				$arguments[] = $parameter->getDefaultValue();

				continue;
			}

			throw $this->missingConstructorParameter($reflection, $parameter);
		}

		try {
			return [$reflection->newInstanceArgs($arguments), $consumed];
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' using constructor hydration.", $reflection->getName()),
				0,
				$exception,
			);
		}
	}

	private function applyWrite(
		object $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		if ($target instanceof stdClass) {
			$target->{(string) $name} = $value;

			return;
		}

		$reflection = $this->getReflection($target);
		$property = $this->getPropertyMatcher($target)->match($name);
		if ($property === null) {
			return;
		}

		try {
			$property->setValue($target, $value);
		} catch (Throwable $exception) {
			throw $this->wrapPropertyFailure(
				$reflection,
				$property,
				$node->getPath(),
				$exception,
			);
		}
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function missingConstructorParameter(
		ReflectionClass $reflection,
		ReflectionParameter $parameter,
	): MappingException {
		return new MappingException(
			sprintf(
				"Unable to resolve required constructor parameter '%s::__construct($%s)'.",
				$reflection->getName(),
				$parameter->getName(),
			),
		);
	}

	private function wrapPropertyFailure(
		ReflectionClass $reflection,
		ReflectionProperty $property,
		string $path,
		Throwable $exception,
	): MappingException {
		return new MappingException(
			sprintf(
				"Failed mapping '%s::$%s' at path '%s'.",
				$reflection->getName(),
				$property->getName(),
				$path,
			),
			0,
			$exception,
		);
	}
}
