<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

final class PropertyRef
{
	public function __construct(
		private PropertySource|RelationRef $source,
		private string $fieldName,
		private ?string $publicPath = null,
	) {
	}

	public function getSource(): PropertySource|RelationRef
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
