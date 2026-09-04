<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;

/**
 * Per-source identity map for adoption.
 *
 * Static: {@see getIdentity()} with precomputed keys (Session intent).
 * Dynamic: {@see getIdentity()} with an identity overlay (schema PK paths).
 */
interface RepresentationSourceIdentities
{
	/**
	 * @param list<string> $sourcePath
	 * @param array<string, mixed>|null $sourceRow identity overlay for dynamic resolution; ignored when static
	 */
	public function getIdentity(array $sourcePath, ?array $sourceRow = null): ?Key;
}
