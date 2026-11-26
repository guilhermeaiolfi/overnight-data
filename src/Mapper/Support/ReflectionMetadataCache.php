<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

/** @internal */
final class ReflectionMetadataCache
{
	private const MAX_CLASSES = 256;

	/** @var array<class-string, ClassReflectionMetadata> */
	private array $entries = [];

	/** @param class-string $class */
	public function forClass(string $class): ClassReflectionMetadata
	{
		if (isset($this->entries[$class])) {
			return $this->entries[$class];
		}

		if (count($this->entries) >= self::MAX_CLASSES) {
			unset($this->entries[array_key_first($this->entries)]);
		}

		return $this->entries[$class] = new ClassReflectionMetadata();
	}

	public function clear(): void
	{
		$this->entries = [];
	}
}
