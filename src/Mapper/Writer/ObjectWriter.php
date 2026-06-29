<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

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

	public function shouldUseConstructorHydration(MappingNode $node): bool
	{
		$target = $node->getTarget();
		if (! is_string($target) || ! class_exists($target) || $target === stdClass::class) {
			return false;
		}

		$reflection = $this->getReflection($target);
		$constructor = $reflection->getConstructor();
		if (! $constructor instanceof ReflectionMethod) {
			return false;
		}

		return $constructor->getNumberOfParameters() > 0
			|| $this->isReadonlyTarget($reflection);
	}

	/**
	 * @param list<array{sourceName: string|int, name: string, value: mixed}> $resolvedEntries
	 *
	 * @return array{target: object, consumed: array<string, true>}
	 */
	public function createTargetUsingConstructor(
		MappingNode $node,
		array $resolvedEntries,
	): array {
		$target = $node->getTarget();
		if (! is_string($target) || $target === stdClass::class) {
			return [
				'target' => $this->createTarget($node),
				'consumed' => [],
			];
		}

		$reflection = $this->getReflection($target);
		$this->assertSupportedTargetOnce($reflection);
		$constructor = $reflection->getConstructor();
		if (! $constructor instanceof ReflectionMethod) {
			return [
				'target' => $this->createTarget($node),
				'consumed' => [],
			];
		}

		$valuesByName = [];
		foreach ($resolvedEntries as $entry) {
			$valuesByName[$entry['name']] = $entry['value'];
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
			return [
				'target' => $reflection->newInstanceArgs($arguments),
				'consumed' => $consumed,
			];
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' using constructor hydration.", $reflection->getName()),
				0,
				$exception,
			);
		}
	}

	public function createTarget(
		MappingNode $node,
	): object {
		$target = $node->getTarget();

		if ($target instanceof stdClass) {
			return clone $target;
		}

		if ($target === stdClass::class) {
			return new stdClass();
		}

		$reflection = $this->getReflection($target);
		$this->assertSupportedTargetOnce($reflection);

		try {
			return $reflection->newInstanceWithoutConstructor();
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' without calling its constructor.", $reflection->getName()),
				0,
				$exception,
			);
		}
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): object {
		if ($target instanceof stdClass) {
			$target->{(string) $name} = $value;

			return $target;
		}

		$reflection = $this->getReflection($target);
		$property = $this->getPropertyMatcher($target)->match($name);
		if ($property === null) {
			return $target;
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

		return $target;
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

	private function getPropertyMatcher(
		object $target,
	): ObjectPropertyMatcher {
		$class = $target::class;

		return $this->propertyMatchers[$class]
			??= new ObjectPropertyMatcher($this->getReflection($target));
	}

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
