<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;

/**
 * Per-source identity map for flat adoption.
 *
 * Supports both modes:
 * - Static: {@see getIdentity()} with precomputed keys (projection / eager query).
 * - Dynamic: {@see getIdentity()} with a source row (query locators + raw row).
 */
interface RepresentationSourceIdentities
{
	/**
	 * @param list<string> $sourcePath
	 * @param array<string, mixed>|null $sourceRow raw row for dynamic resolution; ignored when static
	 */
	public function getIdentity(array $sourcePath, ?array $sourceRow = null): ?Key;
}
