<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;

final class ProjectionFieldShape
{
	public function __construct(
		private string $publicPath,
		private QuerySourceInterface $source,
		private string $fieldName,
		private FieldRef $fieldRef,
		private bool $writable = true,
	) {
	}

	public function getPublicPath(): string
	{
		return $this->publicPath;
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getFieldRef(): FieldRef
	{
		return $this->fieldRef;
	}

	public function isWritable(): bool
	{
		return $this->writable;
	}
}
