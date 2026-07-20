<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

/**
 * PHP-owned generators run above database adapters before INSERT/UPDATE.
 */
interface PhpFieldGeneratorInterface extends FieldGeneratorInterface
{
	public function generate(GenerationContext $context): mixed;
}
