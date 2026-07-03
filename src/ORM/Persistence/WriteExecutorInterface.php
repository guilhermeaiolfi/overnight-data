<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

interface WriteExecutorInterface
{
	public function execute(WriteCommandInterface $command): WriteResult;
}
