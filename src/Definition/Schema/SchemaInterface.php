<?php

declare(strict_types=1);

namespace ON\Data\Definition\Schema;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;

interface SchemaInterface
{
	public function end(): FieldInterface|RelationInterface;
}
