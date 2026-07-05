<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\State\RepresentationRelationBinding;
use Throwable;

final class RepresentationRelationReader
{
	private RepresentationValueReader $reader;

	public function __construct(?RepresentationValueReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationValueReader();
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 *
	 * @return list<object>
	 */
	public function readItems(
		object $representation,
		RepresentationRelationBinding $binding,
		callable $error,
	): array {
		$value = $this->reader->readPath($representation, $binding->getPath());
		if ($value === null) {
			return [];
		}

		if (! is_iterable($value)) {
			throw $error(sprintf(
				"Representation relation path '%s' must contain an iterable value or null.",
				$binding->getPath()
			));
		}

		$items = [];
		foreach ($value as $item) {
			if (! is_object($item)) {
				throw $error(sprintf(
					"Representation relation path '%s' can only contain objects.",
					$binding->getPath()
				));
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 */
	public function readTarget(
		object $representation,
		RepresentationRelationBinding $binding,
		callable $error,
	): ?object {
		$value = $this->reader->readPath($representation, $binding->getPath());
		if ($value === null || is_object($value)) {
			return $value;
		}

		throw $error(sprintf(
			"Representation relation path '%s' must contain an object value or null.",
			$binding->getPath()
		));
	}
}
