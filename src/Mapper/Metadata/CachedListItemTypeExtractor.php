<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use InvalidArgumentException;
use ReflectionProperty;

final class CachedListItemTypeExtractor implements ListItemTypeExtractorInterface
{
	/** @var array<string, class-string|null> */
	private array $types = [];

	public function __construct(
		private readonly ListItemTypeExtractorInterface $extractor,
		private readonly ?int $maxEntries = 256,
		private readonly bool $cacheMisses = false,
	) {
		if ($maxEntries !== null && $maxEntries < 1) {
			throw new InvalidArgumentException('The list item type cache limit must be positive.');
		}
	}

	public function extract(ReflectionProperty $property): ?string
	{
		$key = $property->getDeclaringClass()->getName() . '::$' . $property->getName();
		if (array_key_exists($key, $this->types)) {
			return $this->types[$key];
		}

		$type = $this->extractor->extract($property);
		if ($type === null && ! $this->cacheMisses) {
			return null;
		}

		if ($this->maxEntries !== null && count($this->types) >= $this->maxEntries) {
			unset($this->types[array_key_first($this->types)]);
		}

		return $this->types[$key] = $type;
	}
}
