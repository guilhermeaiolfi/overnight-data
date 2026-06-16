<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Support\DefinitionNode;

final class CustomRelationOptions extends DefinitionNode
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'strategy' => 'default',
			'flags' => [],
		];
	}

	public function strategy(string $strategy): self
	{
		$this->set('strategy', $strategy);

		return $this;
	}

	public function getStrategy(): string
	{
		return (string) $this->get('strategy');
	}

	public function flags(array $flags): self
	{
		$this->set('flags', $flags);

		return $this;
	}

	public function getFlags(): array
	{
		$flags = $this->get('flags');

		return is_array($flags) ? $flags : [];
	}

	public function end(): CustomOwnedRelation
	{
		$owner = $this->owner();

		return $owner instanceof CustomOwnedRelation
			? $owner
			: throw new LogicException('Custom relation options owner is invalid.');
	}
}
