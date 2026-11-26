<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;

final class InsertCommand extends RowCommand
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		CollectionInterface $collection,
		array $values,
		?ExpectedAffectedRows $expectedAffectedRows = null,
	) {
		parent::__construct($collection, 'Insert', ['values' => $values], $expectedAffectedRows);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->slots['values'];
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function setValues(array $values): void
	{
		$this->slots['values'] = $values;
	}
}
