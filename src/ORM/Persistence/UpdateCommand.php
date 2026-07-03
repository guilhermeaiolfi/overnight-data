<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidWriteCommandException;

final class UpdateCommand implements WriteCommandInterface
{
	/**
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $changes
	 */
	public function __construct(
		private string $collectionName,
		private array $identity,
		private array $changes,
	) {
		if ($identity === []) {
			throw new InvalidWriteCommandException('Update command identity cannot be empty.');
		}

		if ($changes === []) {
			throw new InvalidWriteCommandException('Update command changes cannot be empty.');
		}

		$changedIdentityFields = array_keys(array_intersect_key($changes, $identity));

		if ($changedIdentityFields !== []) {
			throw new InvalidWriteCommandException(sprintf(
				"Update command changes cannot include identity fields: '%s'.",
				implode("', '", $changedIdentityFields),
			));
		}
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	public function getKind(): WriteCommandKind
	{
		return WriteCommandKind::UPDATE;
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
