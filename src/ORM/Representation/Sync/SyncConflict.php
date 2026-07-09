<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;
final class SyncConflict
{
	public function __construct(
		private string $path,
		private mixed $baselineValue,
		private mixed $recordValue,
		private mixed $representationValue,
	) {
		if ($path === '') {
			throw new SyncException('Sync conflict path cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getBaselineValue(): mixed
	{
		return $this->baselineValue;
	}

	public function getRecordValue(): mixed
	{
		return $this->recordValue;
	}

	public function getRepresentationValue(): mixed
	{
		return $this->representationValue;
	}
}
