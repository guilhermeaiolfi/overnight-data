<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Sync\PathReadResult;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
use ON\Data\Schema\RepresentationSchema;

/** @internal Bind all source paths before the caller registers the representation. */
final class CleanRepresentationBinder
{
	/**
	 * @param callable(string): PathReadResult<mixed> $read
	 * @return array<string, RecordState>
	 */
	public static function bind(RepresentationSchema $schema, RecordStateStore $records, callable $read, ?Key $rootKey): array
	{
		$sources = $schema->getSources();
		if ($sources === [] && $schema->getRelations() !== []) {
			$key = $rootKey ?? throw StateException::writableResultMissingIdentity($schema->getCollectionName(), $schema->getCollection()->getPrimaryKey());

			return ['' => $records->bindClean($key, $key->getValues())];
		}

		$keys = [];
		$valuesByKey = [];
		foreach ($sources as $source) {
			[$reads, $values] = RepresentationSourceReader::read($source, $read);
			$key = RepresentationSourceReader::resolveKey($source, $reads, $source->isRoot() ? $rootKey : null)
				?? throw StateException::writableResultMissingIdentity($source->getCollection()->getName(), $source->getCollection()->getPrimaryKey());
			$keys[$source->getPathKey()] = $key;
			$hash = $key->getHash();
			if (isset($valuesByKey[$hash])) {
				$valuesByKey[$hash] += $values;
			} else {
				$valuesByKey[$hash] = $values;
			}
		}

		// A self-join can expose the same row through several paths, each with different fields.
		$bound = [];
		$byKey = [];
		foreach ($keys as $path => $key) {
			$hash = $key->getHash();
			$bound[$path] = $byKey[$hash] ??= $records->bindClean($key, $valuesByKey[$hash]);
		}

		return $bound;
	}
}
