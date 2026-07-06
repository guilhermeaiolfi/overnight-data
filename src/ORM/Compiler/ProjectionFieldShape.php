<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Intermediate compile artifact: public representation path, backing field name,
 * and the source object used to resolve collection/record identity.
 *
 * Exists as the common input to ProjectionBindingAssembler so query selections
 * and manual property declarations converge before binding assembly.
 */
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
