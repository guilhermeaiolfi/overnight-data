<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;

interface CommandInterface
{
	public function getCollection(): CollectionInterface;
}
