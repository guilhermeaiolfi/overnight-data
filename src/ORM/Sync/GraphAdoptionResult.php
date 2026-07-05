<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\State\TrackedRepresentation;

final class GraphAdoptionResult
{
	/** @var list<TrackedRepresentation> */
	private array $trackedRepresentations;

	/**
	 * @param list<TrackedRepresentation> $trackedRepresentations
	 */
	public function __construct(array $trackedRepresentations)
	{
		$this->trackedRepresentations = array_values($trackedRepresentations);
	}

	/**
	 * @return list<TrackedRepresentation>
	 */
	public function getTrackedRepresentations(): array
	{
		return $this->trackedRepresentations;
	}

	public function getCount(): int
	{
		return count($this->trackedRepresentations);
	}

	public function isEmpty(): bool
	{
		return $this->trackedRepresentations === [];
	}
}
