<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class UpdateCommand implements CommandInterface
{
	/**
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $changes
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $identity,
		private array $changes,
	) {
		if ($identity === []) {
			throw new InvalidCommandException('Update command identity cannot be empty.');
		}

		if ($changes === []) {
			throw new InvalidCommandException('Update command changes cannot be empty.');
		}

		$changedIdentityFields = array_keys(array_intersect_key($changes, $identity));

		if ($changedIdentityFields !== []) {
			throw new InvalidCommandException(sprintf(
				"Update command changes cannot include identity fields: '%s'.",
				implode("', '", $changedIdentityFields),
			));
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

	/**
	 * @return array<string, mixed>
	 */
	public function getChanges(): array
	{
		return $this->changes;
	}
}
