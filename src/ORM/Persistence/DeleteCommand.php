<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class DeleteCommand implements CommandInterface
{
	/**
	 * @param array<string, mixed> $identity
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $identity,
	) {
		if ($identity === []) {
			throw new InvalidCommandException('Delete command identity cannot be empty.');
		}
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array
	{
		return $this->identity;
	}
}
