<?php

declare(strict_types=1);

namespace ON\Data\Definition\Plugin;

use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Interface\InterfaceTrait;

/**
 * Editor widget and list/read presentation for a field or relation.
 */
final class UiDefinitionPlugin extends DefinitionPlugin
{
	use DisplayTrait;
	use InterfaceTrait;

	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
		];
	}

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->display = null;
		$this->interface = null;
	}
}
