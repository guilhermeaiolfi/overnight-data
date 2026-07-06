<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;

final class ProjectionSourceTarget
{
	public function __construct(
		private CollectionInterface $collection,
		private RepresentationBinding $binding,
		private ?RecordState $recordState = null,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getBinding(): RepresentationBinding
	{
		return $this->binding;
	}

	public function getRecordState(): ?RecordState
	{
		return $this->recordState;
	}

	public function isConcrete(): bool
	{
		return $this->recordState instanceof RecordState;
	}
}
