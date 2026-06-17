<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectToArrayMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		return is_object($source)
			&& ! $source instanceof stdClass
			&& ! $source instanceof DateTimeInterface
			&& ! $source instanceof BackedEnum
			&& is_array($target);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): array {
		$result = [];
		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || ! $property->isInitialized($source) || $this->isHidden($property)) {
				continue;
			}

			$key = $this->resolveTargetKey($property);
			$value = $this->convertOutbound($property->getValue($source), $property, $context->withPathSegment($property->getName()));

			$result[$key] = $value;
		}

		return $result;
	}

	private function isHidden(ReflectionProperty $property): bool
	{
		return $property->getAttributes(Hidden::class) !== [];
	}

	private function resolveTargetKey(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapTo::class);
		if ($attributes === []) {
			return $property->getName();
		}

		return $attributes[0]->newInstance()->getName();
	}
}
