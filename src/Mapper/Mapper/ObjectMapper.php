<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectMapper implements MapperInterface
{
	/** @var array<class-string, list<ReflectionProperty>> */
	private array $sourcePropertiesByClass = [];

	/** @var array<class-string, array<string, bool>> */
	private array $hiddenSourcePropertiesByClass = [];

	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		return is_object($source)
			&& ! $source instanceof DateTimeInterface
			&& ! $source instanceof BackedEnum
			&& ! $source instanceof RepresentationInterface;
	}

	public function map(MappingContext $context): mixed
	{
		$source = $context->getSource();
		if (! is_object($source)) {
			throw new MappingException('ObjectMapper can only map object sources.');
		}

		if ($source instanceof stdClass) {
			foreach (get_object_vars($source) as $name => $value) {
				$context->write(
					name: $name,
					value: $value,
				);
			}

			return $context->getResult();
		}

		$className = $source::class;

		foreach ($this->getSourceProperties($source) as $property) {
			if (! $property->isInitialized($source) || $this->isHidden($className, $property)) {
				continue;
			}

			$context->write(
				name: $property->getName(),
				value: $property->getValue($source),
			);
		}

		return $context->getResult();
	}

	/**
	 * @return list<ReflectionProperty>
	 */
	private function getSourceProperties(object $source): array
	{
		$className = $source::class;
		if (array_key_exists($className, $this->sourcePropertiesByClass)) {
			return $this->sourcePropertiesByClass[$className];
		}

		$properties = [];
		$hiddenProperties = [];
		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic()) {
				continue;
			}

			$properties[] = $property;
			$hiddenProperties[$property->getName()] = $property->getAttributes(Hidden::class) !== [];
		}

		$this->sourcePropertiesByClass[$className] = $properties;
		$this->hiddenSourcePropertiesByClass[$className] = $hiddenProperties;

		return $properties;
	}

	private function isHidden(string $className, ReflectionProperty $property): bool
	{
		return $this->hiddenSourcePropertiesByClass[$className][$property->getName()] ?? false;
	}
}
