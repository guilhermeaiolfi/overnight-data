<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectMapper extends Mapper
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

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		if ($node->isCollection()) {
			return $this->mapCollection($node, $mapperManager);
		}

		$source = $node->getValue();
		if (! is_object($source)) {
			throw new MappingException('ObjectMapper can only map object sources.');
		}

		$writer = $mapperManager->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$resolvers = $mapperManager->createResolverChain($frame->getContext());
		$converter = $mapperManager->createFieldConversionCoordinator();

		if ($source instanceof stdClass) {
			foreach (get_object_vars($source) as $name => $value) {
				$child = $frame->child($name, $value);
				$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
				$result = $writer->write($result, $child, $mappedValue);
			}

			return $writer->finish($result, $frame->getContext());
		}

		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || ! $property->isInitialized($source) || $this->isHidden($property)) {
				continue;
			}

			$name = $this->resolveName($property);
			$child = $frame->child($name, $property->getValue($source), $property);
			$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
			$result = $writer->write($result, $child, $mappedValue);
		}

		return $writer->finish($result, $frame->getContext());
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
