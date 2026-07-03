<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

interface WriteCommandInterface
{
	public function getCollectionName(): string;

	public function getKind(): WriteCommandKind;
}
