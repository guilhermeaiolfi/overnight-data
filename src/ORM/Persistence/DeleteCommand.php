<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidWriteCommandException;

final class DeleteCommand implements WriteCommandInterface
{
	/**
	 * @param array<string, mixed> $identity
	 */
	public function __construct(
		private string $collectionName,
		private array $identity,
	) {
		if ($identity === []) {
			throw new InvalidWriteCommandException('Delete command identity cannot be empty.');
		}
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	public function getKind(): WriteCommandKind
	{
		return WriteCommandKind::DELETE;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array
	{
		return $this->identity;
	}
}
