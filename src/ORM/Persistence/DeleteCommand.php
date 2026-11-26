<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;

final class DeleteCommand extends RowCommand
{
	/**
	 * @param array<string, mixed> $identity
	 */
	public function __construct(
		CollectionInterface $collection,
		array $identity,
		?ExpectedAffectedRows $expectedAffectedRows = null,
	) {
		if ($identity === []) {
			throw new InvalidCommandException('Delete command identity cannot be empty.');
		}

		parent::__construct($collection, 'Delete', ['identity' => $identity], $expectedAffectedRows);
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
}
