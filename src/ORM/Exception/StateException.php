<?php

declare(strict_types=1);

namespace ON\Data\ORM\Exception;

class StateException extends OrmException
{
	/**
	 * Writable hydrate cannot invent identity columns or treat the row as new.
	 *
	 * @param list<string> $primaryKey
	 */
	public static function writableResultMissingIdentity(string $collectionName, array $primaryKey): self
	{
		$fields = $primaryKey === [] ? 'id' : implode(', ', $primaryKey);
		$examples = implode(', ', array_map(
			static fn (string $field): string => $collectionName . '.' . $field,
			$primaryKey === [] ? ['id'] : $primaryKey,
		));

		return new self(sprintf(
			"Cannot track a writable query result for collection '%s' because its primary key (%s) was not fetched. Select those identity fields in the query (for example %s). writable() does not add them, and results without identity are never tracked.",
			$collectionName,
			$fields,
			$examples,
		));
	}
}
