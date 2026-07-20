<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\ORM\Record\RecordState;

final readonly class GenerationContext
{
	public function __construct(
		public CollectionInterface $collection,
		public FieldInterface $field,
		public RecordState $record,
		public int $when,
		public mixed $arg = null,
	) {
	}
}
