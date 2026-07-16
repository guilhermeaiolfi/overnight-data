<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use InvalidArgumentException;
use ON\Data\Key;

/**
 * Flat related-path intent recorded by IntentBuilder until sync().
 *
 * @param 'update'|'create' $operation
 */
final class FlatIntentOp
{
	/**
	 * @param Key|array<string, mixed>|null $key
	 */
	public function __construct(
		private string $path,
		private string $operation,
		private Key|array|null $key = null,
	) {
		if ($path === '') {
			throw new InvalidArgumentException('Flat intent path cannot be empty.');
		}

		if ($operation !== 'update' && $operation !== 'create') {
			throw new InvalidArgumentException(sprintf("Unknown flat intent operation '%s'.", $operation));
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getOperation(): string
	{
		return $this->operation;
	}

	/**
	 * @return Key|array<string, mixed>|null
	 */
	public function getKey(): Key|array|null
	{
		return $this->key;
	}

	public function isCreate(): bool
	{
		return $this->operation === 'create';
	}

	public function isUpdate(): bool
	{
		return $this->operation === 'update';
	}
}
