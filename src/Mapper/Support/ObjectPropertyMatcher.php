<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapFrom;
use ReflectionClass;
use ReflectionProperty;

final class ObjectPropertyMatcher
{
	public function __construct(
		private readonly ReflectionClass $reflection,
	) {
	}

	public function match(string|int $effectiveName): ?ReflectionProperty
	{
		$name = (string) $effectiveName;
		$fallback = null;

		foreach ($this->reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic()) {
				continue;
			}

			$attributes = $property->getAttributes(MapFrom::class);
			if ($attributes !== [] && $attributes[0]->newInstance()->getName() === $name) {
				return $property;
			}

			if ($property->getName() === $name) {
				$fallback = $property;
			}
		}

		return $fallback;
	}
}
