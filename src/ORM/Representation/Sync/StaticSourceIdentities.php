<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Adoption identity map with already-concrete Keys (path → {@see Key}).
 *
 * Built once for an adoption (e.g. from Session intent). Not a per-row map.
 */
final class StaticSourceIdentities
{
	/**
	 * @param array<string, Key> $keysByPathKey
	 */
	public function __construct(
		private array $keysByPathKey = [],
	) {
	}

	public static function fromIntent(
		RepresentationSchema $schema,
		RepresentationIntent $intent,
	): self {
		$sourcesByPathKey = [];
		foreach ($schema->getSources() as $source) {
			$sourcesByPathKey[$source->getPathKey()] = $source;
		}

		$keys = [];
		$rootIdentity = $intent->getIdentity();
		if ($rootIdentity !== null) {
			$rootPathKey = '';
			$root = $sourcesByPathKey[$rootPathKey] ?? null;
			if ($root instanceof RepresentationSource) {
				$keys[$rootPathKey] = $root->getCollection()->getKey($rootIdentity);
			}
		}

		foreach ($intent->getFlatOps() as $op) {
			if ($op->getKey() === null) {
				continue;
			}

			$source = $sourcesByPathKey[$op->getPath()] ?? null;
			if (! $source instanceof RepresentationSource) {
				continue;
			}

			$keys[$op->getPath()] = $source->getCollection()->getKey($op->getKey());
		}

		return new self($keys);
	}

	public function getIdentity(string $sourcePathKey): ?Key
	{
		return $this->keysByPathKey[$sourcePathKey] ?? null;
	}
}
