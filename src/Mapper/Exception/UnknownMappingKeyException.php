<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Exception;

final class UnknownMappingKeyException extends MappingException
{
	/** @param class-string $targetClass */
	public function __construct(
		private readonly string $path,
		private readonly string $targetClass,
	) {
		parent::__construct(sprintf("Unknown input key at path '%s' when mapping to '%s'.", $path, $targetClass));
	}

	public function getPath(): string
	{
		return $this->path;
	}

	/** @return class-string */
	public function getTargetClass(): string
	{
		return $this->targetClass;
	}
}
