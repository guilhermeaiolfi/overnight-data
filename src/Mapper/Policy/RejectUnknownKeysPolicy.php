<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Policy;

use ON\Data\Mapper\Exception\UnknownMappingKeyException;

final class RejectUnknownKeysPolicy implements UnknownKeyPolicyInterface
{
	public function handle(string $path, string $targetClass): void
	{
		throw new UnknownMappingKeyException($path, $targetClass);
	}
}
