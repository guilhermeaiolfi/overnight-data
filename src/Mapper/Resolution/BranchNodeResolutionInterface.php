<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolution;

interface BranchNodeResolutionInterface extends ResolutionNodeInterface
{
	public function getTarget(): mixed;

	/**
	 * @return list<mixed>
	 */
	public function getArguments(): array;

	public function isCollection(): bool;
}
