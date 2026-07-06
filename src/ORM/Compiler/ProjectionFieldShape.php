<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

final class ProjectionFieldShape
{
	public function __construct(
		private string $publicPath,
		private object $source,
		private string $fieldName,
		private bool $writable = true,
	) {
	}

	public function getPublicPath(): string
	{
		return $this->publicPath;
	}

	public function getSource(): object
	{
		return $this->source;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function isWritable(): bool
	{
		return $this->writable;
	}
}
