<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectWalker extends Walker
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_object($source)
			&& ! $source instanceof DateTimeInterface
			&& ! $source instanceof BackedEnum
			&& ! $source instanceof RepresentationInterface;
	}

	protected function getNodes(
		mixed $source,
		MappingContext $context,
	): iterable {
		if ($source instanceof stdClass) {
			foreach (get_object_vars($source) as $name => $value) {
				yield new MappingNode($name, $value, $context);
			}

			return;
		}

		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || ! $property->isInitialized($source) || $this->isHidden($property)) {
				continue;
			}

			$name = $this->resolveName($property);
			yield new MappingNode($name, $property->getValue($source), $context, $property);
		}
	}

	private function isHidden(ReflectionProperty $property): bool
	{
		return $property->getAttributes(Hidden::class) !== [];
	}

	private function resolveName(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapTo::class);
		if ($attributes === []) {
			return $property->getName();
		}

		return $attributes[0]->newInstance()->getName();
	}
}
