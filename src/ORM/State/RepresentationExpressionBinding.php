<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RepresentationExpressionBinding
{
	public function __construct(
		private string $path,
		private ?string $selectionKey = null,
	) {
		if ($path === '') {
			throw new StateException('Representation expression binding path cannot be empty.');
		}

		if ($selectionKey === '') {
			throw new StateException('Representation expression binding selection key cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getSelectionKey(): ?string
	{
		return $this->selectionKey;
	}

	public function isWritable(): bool
	{
		return false;
	}

	public function isReadOnly(): bool
	{
		return true;
	}
}
