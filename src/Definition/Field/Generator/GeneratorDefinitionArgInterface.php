<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

use ON\Data\Definition\Field\FieldInterface;

/**
 * Allows a generator instance passed to {@see FieldInterface::generator()}
 * to flatten into the array-backed definition as class + arg.
 */
interface GeneratorDefinitionArgInterface
{
	public function getDefinitionArg(): mixed;
}
