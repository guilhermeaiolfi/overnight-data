<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use ON\Data\ORM\Persistence\CommandInterface;

/** Optional snapshot data for custom commands. It does not make a command reversible. */
interface InspectableCommandInterface extends CommandInterface
{
	/**
	 * Returns canonical identity and values after execution, with any references already resolved.
	 *
	 * @return array{identity?: array<string, mixed>, values?: array<string, mixed>}
	 */
	public function getHistoryData(): array;
}
