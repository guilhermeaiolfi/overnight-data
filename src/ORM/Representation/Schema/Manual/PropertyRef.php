<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
/**
 * Declares one manual projection field: source, backing field name, and optional
 * public path alias via as().
 *
 * Exists as the manual equivalent of a normalized RepresentationFieldShape before
 * Builder collects shapes for ManualRepresentationSchemaCompiler.
 */
final class PropertyRef
{
	public function __construct(
		private ManualRepresentationSourceInterface|RelationRef $source,
		private string $fieldName,
		private ?string $publicPath = null,
	) {
	}

	public function getSource(): ManualRepresentationSourceInterface|RelationRef
	{
		return $this->source;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getPublicPath(): string
	{
		return $this->publicPath ?? $this->fieldName;
	}

	public function as(string $publicPath): self
	{
		return new self($this->source, $this->fieldName, $publicPath);
	}
}
