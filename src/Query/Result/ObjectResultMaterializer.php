<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Exception\ObjectExportException;
use ReflectionClass;
use ReflectionNamedType;
use stdClass;

final class ObjectResultMaterializer
{
	/**
	 * @var array<class-string, ReflectionClass<object>>
	 */
	private array $reflections = [];

	public function materialize(array $data, string $class): object
	{
		return $this->convertAssociativeArray($data, $class);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<object>
	 */
	public function materializeAll(array $rows, string $class): array
	{
		$materialized = [];

		foreach ($rows as $row) {
			$materialized[] = $this->materialize($row, $class);
		}

		return $materialized;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function convertAssociativeArray(array $data, string $class): object
	{
		$object = $this->createInstance($class);

		foreach ($data as $key => $value) {
			if ($class !== stdClass::class) {
				$this->assertKnownPublicProperty($class, $key);
			}

			$nestedClass = $this->resolveNestedClass($class, $key);
			$object->{$key} = $this->convertValue($value, $nestedClass);
		}

		return $object;
	}

	private function convertValue(mixed $value, string $class): mixed
	{
		if ($value === null || is_scalar($value)) {
			return $value;
		}

		if (is_object($value)) {
			return $value;
		}

		if (! is_array($value)) {
			return $value;
		}

		if ($this->isListArray($value)) {
			$converted = [];

			foreach ($value as $item) {
				$converted[] = $this->convertValue($item, stdClass::class);
			}

			return $converted;
		}

		return $this->convertAssociativeArray($value, $class);
	}

	/**
	 * @param array<mixed> $value
	 */
	private function isListArray(array $value): bool
	{
		return array_is_list($value);
	}

	private function createInstance(string $class): object
	{
		ObjectExportClassValidator::assertSupported($class);

		if ($class === stdClass::class) {
			return new stdClass();
		}

		return new $class();
	}

	/**
	 * @param class-string $class
	 */
	private function assertKnownPublicProperty(string $class, string $property): void
	{
		$reflection = $this->getReflection($class);

		if (! $reflection->hasProperty($property)) {
			throw ObjectExportException::unknownProperty($class, $property);
		}

		$propertyReflection = $reflection->getProperty($property);

		if (! $propertyReflection->isPublic()) {
			throw ObjectExportException::unknownProperty($class, $property);
		}
	}

	/**
	 * @param class-string $class
	 */
	private function resolveNestedClass(string $class, string $property): string
	{
		if ($class === stdClass::class) {
			return stdClass::class;
		}

		$reflection = $this->getReflection($class);
		$propertyReflection = $reflection->getProperty($property);

		if (! $propertyReflection->isPublic()) {
			return stdClass::class;
		}

		$type = $propertyReflection->getType();

		if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
			return $type->getName();
		}

		return stdClass::class;
	}

	/**
	 * @param class-string $class
	 *
	 * @return ReflectionClass<object>
	 */
	private function getReflection(string $class): ReflectionClass
	{
		return $this->reflections[$class] ??= new ReflectionClass($class);
	}
}
