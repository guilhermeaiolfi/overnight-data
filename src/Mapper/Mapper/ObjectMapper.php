<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectMapper implements MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_object($source)
			&& ! $source instanceof DateTimeInterface
			&& ! $source instanceof BackedEnum
			&& ! $source instanceof RepresentationInterface;
	}

	public function map(MappingRuntime $runtime): mixed
	{
		$source = $runtime->getSource();
		if (! is_object($source)) {
			throw new MappingException('ObjectMapper can only map object sources.');
		}

		if ($source instanceof stdClass) {
			foreach (get_object_vars($source) as $name => $value) {
				$runtime->write(
					name: $name,
					value: $value,
				);
			}

			return $runtime->getResult();
		}

		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || ! $property->isInitialized($source) || $this->isHidden($property)) {
				continue;
			}

			$runtime->write(
				name: $property->getName(),
				value: $property->getValue($source),
			);
		}

		return $runtime->getResult();
	}

	private function isHidden(ReflectionProperty $property): bool
	{
		return $property->getAttributes(Hidden::class) !== [];
	}
}
