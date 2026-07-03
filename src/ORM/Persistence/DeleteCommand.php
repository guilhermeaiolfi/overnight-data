<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;

final class DeleteCommand implements CommandInterface
{
	/**
	 * @param array<string, mixed> $identity
	 */
	public function __construct(
		private string $collectionName,
		private array $identity,
	) {
		if ($identity === []) {
			throw new InvalidCommandException('Delete command identity cannot be empty.');
		}
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array
	{
		return $this->identity;
	}
}
