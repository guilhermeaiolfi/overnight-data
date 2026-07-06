<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

final class ManualProjectionPropertyRef
{
	public function __construct(
		private ManualProjectionPropertySource|ManualProjectionRelationRef $source,
		private string $fieldName,
		private ?string $publicPath = null,
	) {
	}

	public function getSource(): ManualProjectionPropertySource|ManualProjectionRelationRef
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
