<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class DeleteCommand implements CommandInterface
{
	private ExpectedAffectedRows $expectedAffectedRows;

	/**
	 * @param array<string, mixed> $identity
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $identity,
		?ExpectedAffectedRows $expectedAffectedRows = null,
	) {
		if ($identity === []) {
			throw new InvalidCommandException('Delete command identity cannot be empty.');
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

	public function getExpectedAffectedRows(): ExpectedAffectedRows
	{
		return $this->expectedAffectedRows;
	}
}
