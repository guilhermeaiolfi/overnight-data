<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class TrackedRepresentationResolver
{
	public function __construct(private TrackedRepresentationMap $representations)
	{
	}

	public function getTrackedRepresentation(object $object, string $path): TrackedRepresentation
	{
		$tracked = $this->representations->get($object);
		if ($tracked instanceof TrackedRepresentation) {
			return $tracked;
		}

		throw new SyncException(sprintf(
			"Representation relation path '%s' references an object that is not tracked; adopt or track the related object before synchronization.",
			$path
		));
	}
}
