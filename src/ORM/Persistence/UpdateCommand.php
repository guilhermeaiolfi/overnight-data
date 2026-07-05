<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class UpdateCommand implements CommandInterface
{
	private ExpectedAffectedRows $expectedAffectedRows;

	/**
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $changes
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $identity,
		private array $changes,
		?ExpectedAffectedRows $expectedAffectedRows = null,
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

		$this->expectedAffectedRows = $expectedAffectedRows ?? ExpectedAffectedRows::exactly(1);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array
	{
		return $this->identity;
	}

	/**
	 * @param array<string, mixed> $identity
	 */
	public function setIdentity(array $identity): void
	{
		$this->identity = $identity;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getChanges(): array
	{
		return $this->changes;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function setChanges(array $changes): void
	{
		$this->changes = $changes;
	}

	public function getExpectedAffectedRows(): ExpectedAffectedRows
	{
		return $this->expectedAffectedRows;
	}
}
