<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Policy;

interface UnknownKeyPolicyInterface
{
	/**
	 * Handle an unmatched input key. Returning lets the mapper skip it and continue.
	 *
	 * @param class-string $targetClass
	 */
	public function handle(string $path, string $targetClass): void;
}
