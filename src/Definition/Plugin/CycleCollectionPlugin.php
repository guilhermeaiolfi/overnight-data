<?php

declare(strict_types=1);

namespace ON\Data\Definition\Plugin;

use stdClass;

/**
 * Cycle ORM bridge metadata for a collection (entity, repository, mapper, scope).
 *
 * Unused by ON\Data Session persistence, query loading, and command execution.
 */
final class CycleCollectionPlugin extends DefinitionPlugin
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'entity' => stdClass::class,
			'scope' => null,
			'repository' => null,
			'mapper' => null,
		];
	}

	public function entity(string $entity): self
	{
		$this->set('entity', $entity);

		return $this;
	}

	public function getEntity(): string
	{
		return (string) $this->get('entity');
	}

	public function scope(string $scope): self
	{
		$this->set('scope', $scope);

		return $this;
	}

	public function getScope(): ?string
	{
		$value = $this->get('scope');

		return is_string($value) ? $value : null;
	}

	public function repository(?string $repository): self
	{
		$this->set('repository', $repository);

		return $this;
	}

	public function getRepository(): ?string
	{
		$value = $this->get('repository');

		return is_string($value) ? $value : null;
	}

	public function mapper(?string $mapper): self
	{
		$this->set('mapper', $mapper);

		return $this;
	}

	public function getMapper(): ?string
	{
		$value = $this->get('mapper');

		return is_string($value) ? $value : null;
	}
}
