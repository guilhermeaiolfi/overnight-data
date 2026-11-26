<?php

declare(strict_types=1);

namespace ON\Data\Query\Schema;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapCollection;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Support\DocblockListTargetResolver;
use ON\Data\Schema\Exception\InvalidSchemaException;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\SchemaFields;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use stdClass;

/**
 * Compiles a DTO class into a structural {@see RepresentationSchema} against a
 * root collection using public properties, {@see MapFrom}, {@see Hidden}, and
 * nested class types, {@see MapCollection}, or docblock list types.
 *
 * Returns null when the class has no declared visible public properties (such as
 * {@see stdClass} or a private-property class backed by a custom mapper).
 */
final class ClassRepresentationSchemaCompiler
{
	public function __construct(
		private readonly DocblockListTargetResolver $listTargetResolver = new DocblockListTargetResolver(),
	) {
	}

	/**
	 * @param class-string|ReflectionClass<object> $class
	 */
	public function compile(CollectionInterface $collection, string|ReflectionClass $class): ?RepresentationSchema
	{
		$reflection = is_string($class) ? new ReflectionClass($class) : $class;
		if ($reflection->getName() === stdClass::class) {
			return null;
		}

		return $this->compileClass($collection, $reflection, [
			$this->stackKey($collection, $reflection->getName()) => true,
		]);
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 * @param array<string, true> $visited
	 */
	private function compileClass(
		CollectionInterface $collection,
		ReflectionClass $reflection,
		array $visited,
	): ?RepresentationSchema {
		$properties = $this->visibleProperties($reflection);

		if ($properties === []) {
			return null;
		}

		$schema = new RepresentationSchema($collection);

		foreach ($properties as $property) {
			$this->compileProperty($schema, $collection, $reflection, $property, $visited);
		}

		if ($schema->getPaths() === []) {
			throw new InvalidSchemaException(sprintf(
				"DTO class '%s' has no properties matching any field or relation on collection '%s'.",
				$reflection->getName(),
				$collection->getName(),
			));
		}

		SchemaFields::assembleFlatSourcePrimaryKeys($schema);
		SchemaFields::assemblePrimaryKeyFields($schema, $collection);

		return $schema;
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 * @param array<string, true> $visited
	 */
	private function compileProperty(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		ReflectionClass $reflection,
		ReflectionProperty $property,
		array $visited,
	): void {
		$propertyName = $property->getName();
		$mapFrom = $this->attributeName($property, MapFrom::class);
		$mapTo = $this->attributeName($property, MapTo::class);
		$hasExplicitMapFrom = $mapFrom !== null;
		$sourceSpec = $hasExplicitMapFrom ? trim($mapFrom) : $propertyName;

		if ($sourceSpec === '') {
			throw new InvalidSchemaException(sprintf(
				"Property '%s::\$%s' has an empty MapFrom path.",
				$reflection->getName(),
				$propertyName,
			));
		}

		// Use MapTo when it mirrors a non-dotted MapFrom on an eager property so
		// both ObjectWriter (hydration) and Mapper-backed RepresentationReader align.
		$path = ($mapTo !== null && $hasExplicitMapFrom && $mapTo === $sourceSpec && ! str_contains($sourceSpec, '.') && ! $property->isPromoted())
			? $mapTo
			: $propertyName;

		if (str_contains($sourceSpec, '.')) {
			$this->compileDottedField($schema, $collection, $reflection->getName(), $propertyName, $path, $sourceSpec);

			return;
		}

		if ($collection->hasField($sourceSpec)) {
			$schema->addField(new RepresentationFieldSchema(
				$path,
				$collection,
				$sourceSpec,
				writable: true,
			));

			return;
		}

		if ($collection->hasRelation($sourceSpec)) {
			$this->compileRelation($schema, $collection, $reflection->getName(), $property, $path, $sourceSpec, $visited);

			return;
		}

		if ($hasExplicitMapFrom || ! $this->hasDefaultValue($reflection, $property)) {
			throw new InvalidSchemaException(sprintf(
				"DTO property '%s::\$%s' mapped from '%s' does not match any field or relation on collection '%s'.",
				$reflection->getName(),
				$propertyName,
				$sourceSpec,
				$collection->getName(),
			));
		}
	}

	private function compileDottedField(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		string $class,
		string $propertyName,
		string $path,
		string $sourceSpec,
	): void {
		$segments = explode('.', $sourceSpec);
		foreach ($segments as $segment) {
			if ($segment === '') {
				throw new InvalidSchemaException(sprintf(
					"DTO property '%s::\$%s' has malformed dotted path '%s'.",
					$class,
					$propertyName,
					$sourceSpec,
				));
			}
		}

		$relationSegments = array_slice($segments, 0, -1);
		$fieldName = $segments[array_key_last($segments)];
		$current = $collection;

		foreach ($relationSegments as $segment) {
			$relation = $current->getRelation($segment);
			if (! $relation instanceof RelationInterface) {
				throw new InvalidSchemaException(sprintf(
					"DTO property '%s::\$%s' references unknown relation '%s' on collection '%s'.",
					$class,
					$propertyName,
					$segment,
					$current->getName(),
				));
			}

			$current = $relation->getCollection();
		}

		if (! $current->hasField($fieldName)) {
			throw new InvalidSchemaException(sprintf(
				"DTO property '%s::\$%s' references unknown field '%s' on collection '%s'.",
				$class,
				$propertyName,
				$fieldName,
				$current->getName(),
			));
		}

		$schema->addField(new RepresentationFieldSchema(
			$path,
			$current,
			$fieldName,
			writable: true,
			sourcePath: $relationSegments,
		));
	}

	/**
	 * @param array<string, true> $visited
	 */
	private function compileRelation(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		string $class,
		ReflectionProperty $property,
		string $path,
		string $relationName,
		array $visited,
	): void {
		if ($path !== $relationName) {
			throw new InvalidSchemaException(sprintf(
				"DTO property '%s::\$%s' cannot alias relation '%s' as '%s'; relation properties must match the relation name.",
				$class,
				$property->getName(),
				$relationName,
				$path,
			));
		}

		$relation = $collection->getRelation($relationName);
		if (! $relation instanceof RelationInterface) {
			throw new InvalidSchemaException(sprintf(
				"Relation '%s' does not exist on collection '%s'.",
				$relationName,
				$collection->getName(),
			));
		}

		$targetCollection = $relation->getCollection();
		$nestedClass = $this->resolveNestedDtoClass($property);

		if ($nestedClass !== null) {
			$key = $this->stackKey($targetCollection, $nestedClass);
			if (isset($visited[$key])) {
				throw new InvalidSchemaException(sprintf(
					"Cyclic DTO relation projection detected at '%s::\$%s' -> '%s' on collection '%s'.",
					$class,
					$property->getName(),
					$nestedClass,
					$targetCollection->getName(),
				));
			}

			$relatedSchema = $this->compileClass($targetCollection, new ReflectionClass($nestedClass), $visited + [$key => true])
				?? SchemaFields::defaultSchema($targetCollection);
		} else {
			$relatedSchema = SchemaFields::defaultSchema($targetCollection);
		}

		$schema->addRelation(new RepresentationRelationSchema(
			$path,
			$collection,
			$relationName,
			$relatedSchema,
			loadKnowledge: RelationLoadKnowledge::Full,
		));
	}

	/**
	 * @return class-string|null
	 */
	private function resolveNestedDtoClass(ReflectionProperty $property): ?string
	{
		$type = $property->getType();

		if ($type instanceof ReflectionNamedType) {
			return $this->resolveNamedNestedClass($property, $type);
		}

		if ($type instanceof ReflectionUnionType) {
			$candidate = null;
			$hasListType = false;

			foreach ($type->getTypes() as $member) {
				if (! $member instanceof ReflectionNamedType) {
					continue;
				}

				$name = $member->getName();
				if ($name === 'null') {
					continue;
				}

				if ($member->isBuiltin()) {
					if ($name === 'array' || $name === 'iterable') {
						$hasListType = true;
					}

					continue;
				}

				$resolved = $this->normalizeDtoClass($name);
				if ($resolved !== null) {
					if ($candidate !== null && $candidate !== $resolved) {
						return null;
					}

					$candidate = $resolved;
				}
			}

			if ($candidate !== null) {
				return $candidate;
			}

			if ($hasListType) {
				return $this->normalizeDtoClass($this->listTargetResolver->resolve($property));
			}

			return null;
		}

		if ($type === null) {
			return $this->normalizeDtoClass($this->listTargetResolver->resolve($property));
		}

		return null;
	}

	/**
	 * @return class-string|null
	 */
	private function resolveNamedNestedClass(ReflectionProperty $property, ReflectionNamedType $type): ?string
	{
		if (! $type->isBuiltin()) {
			return $this->normalizeDtoClass($type->getName());
		}

		$name = $type->getName();
		if ($name === 'array' || $name === 'iterable') {
			return $this->normalizeDtoClass($this->listTargetResolver->resolve($property));
		}

		return null;
	}

	/**
	 * @return class-string|null
	 */
	private function normalizeDtoClass(?string $class): ?string
	{
		if ($class === null || $class === '' || $class === stdClass::class || ! class_exists($class)) {
			return null;
		}

		if (
			is_a($class, DateTimeInterface::class, true)
			|| is_a($class, BackedEnum::class, true)
			|| is_a($class, RepresentationInterface::class, true)
		) {
			return null;
		}

		return $class;
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 * @return list<ReflectionProperty>
	 */
	private function visibleProperties(ReflectionClass $reflection): array
	{
		$properties = [];

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || $property->getAttributes(Hidden::class) !== []) {
				continue;
			}

			$properties[] = $property;
		}

		return $properties;
	}

	/**
	 * @param class-string<MapFrom|MapTo> $attributeClass
	 */
	private function attributeName(ReflectionProperty $property, string $attributeClass): ?string
	{
		$attributes = $property->getAttributes($attributeClass);
		if ($attributes === []) {
			return null;
		}

		return $attributes[0]->newInstance()->getName();
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private function hasDefaultValue(ReflectionClass $reflection, ReflectionProperty $property): bool
	{
		if ($property->hasDefaultValue()) {
			return true;
		}

		if (! $property->isPromoted()) {
			return false;
		}

		$constructor = $reflection->getConstructor();
		if ($constructor === null) {
			return false;
		}

		foreach ($constructor->getParameters() as $parameter) {
			if ($parameter->getName() === $property->getName()) {
				return $parameter->isDefaultValueAvailable();
			}
		}

		return false;
	}

	private function stackKey(CollectionInterface $collection, string $class): string
	{
		return $collection->getName() . ':' . $class;
	}
}
