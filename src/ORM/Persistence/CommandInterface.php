<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

interface CommandInterface
{
	public function getCollectionName(): string;
}
