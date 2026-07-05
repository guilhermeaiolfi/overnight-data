<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class RepresentationStateResolver
{
	public function __construct(private RepresentationStore $representations)
	{
	}

	public function getRepresentationState(object $object, string $path): RepresentationState
	{
		$tracked = $this->representations->get($object);
		if ($tracked instanceof RepresentationState) {
			return $tracked;
		}

		throw new SyncException(sprintf(
			"Representation relation path '%s' references an object that is not tracked; adopt or track the related object before synchronization.",
			$path
		));
	}
}
