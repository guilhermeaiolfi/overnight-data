<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapFrom;
use ReflectionClass;
use ReflectionProperty;

final class ObjectPropertyMatcher
{
	/** @var array<string, ReflectionProperty> */
	private array $propertiesByName = [];

	/** @var array<string, ReflectionProperty> */
	private array $propertiesByMapFromName = [];

	public function __construct(
		private readonly ReflectionClass $reflection,
	) {
		$this->indexProperties();
	}

	public function match(string|int $effectiveName): ?ReflectionProperty
	{
		$name = (string) $effectiveName;

		return $this->propertiesByMapFromName[$name]
			?? $this->propertiesByName[$name]
			?? null;
	}

	private function indexProperties(): void
	{
		foreach ($this->reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic()) {
				continue;
			}

			$this->propertiesByName[$property->getName()] = $property;

			$attributes = $property->getAttributes(MapFrom::class);
			if ($attributes === []) {
				continue;
			}

			$mapFromName = $attributes[0]->newInstance()->getName();
			$this->propertiesByMapFromName[$mapFromName] ??= $property;
		}
	}
}
