<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class UpdateCommand extends RowCommand
{
	/**
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $changes
	 */
	public function __construct(
		CollectionInterface $collection,
		array $identity,
		array $changes,
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

		parent::__construct($collection, 'Update', ['identity' => $identity, 'changes' => $changes], $expectedAffectedRows);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array
	{
		return $this->slots['identity'];
	}

	/**
	 * @param array<string, mixed> $identity
	 */
	public function setIdentity(array $identity): void
	{
		$this->slots['identity'] = $identity;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getChanges(): array
	{
		return $this->slots['changes'];
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function setChanges(array $changes): void
	{
		$this->slots['changes'] = $changes;
	}
}
