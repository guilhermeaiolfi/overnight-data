<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RepresentationBinding
{
	/** @var array<string, RepresentationFieldBinding> */
	private array $fields = [];
	/** @var array<string, RepresentationExpressionBinding> */
	private array $expressions = [];
	/** @var array<string, RepresentationRelationBinding> */
	private array $relations = [];
	/** @var list<string> */
	private array $paths = [];

	public function addField(RepresentationFieldBinding $binding): void
	{
		$path = $binding->getPath();
		$this->assertPathIsAvailable($path);

		$this->fields[$path] = $binding;
		$this->paths[] = $path;
	}

	public function hasField(string $path): bool
	{
		return array_key_exists($path, $this->fields);
	}

	public function getField(string $path): RepresentationFieldBinding
	{
		if (! array_key_exists($path, $this->fields)) {
			throw new StateException(sprintf("Representation binding does not contain field path '%s'.", $path));
		}

		return $this->fields[$path];
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getFields(): array
	{
		return array_values($this->fields);
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getWritableFieldBindings(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isWritable()
		));
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getReadOnlyFieldBindings(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isReadOnly()
		));
	}

	public function addExpression(RepresentationExpressionBinding $binding): void
	{
		$path = $binding->getPath();
		$this->assertPathIsAvailable($path);

		$this->expressions[$path] = $binding;
		$this->paths[] = $path;
	}

	public function hasExpression(string $path): bool
	{
		return array_key_exists($path, $this->expressions);
	}

	public function getExpression(string $path): RepresentationExpressionBinding
	{
		if (! array_key_exists($path, $this->expressions)) {
			throw new StateException(sprintf("Representation binding does not contain expression path '%s'.", $path));
		}

		return $this->expressions[$path];
	}

	/**
	 * @return list<RepresentationExpressionBinding>
	 */
	public function getExpressions(): array
	{
		return array_values($this->expressions);
	}

	public function addRelation(RepresentationRelationBinding $binding): void
	{
		$path = $binding->getPath();
		$this->assertPathIsAvailable($path);

		$this->relations[$path] = $binding;
		$this->paths[] = $path;
	}

	public function hasRelation(string $path): bool
	{
		return array_key_exists($path, $this->relations);
	}

	public function getRelation(string $path): RepresentationRelationBinding
	{
		if (! array_key_exists($path, $this->relations)) {
			throw new StateException(sprintf("Representation binding does not contain relation path '%s'.", $path));
		}

		return $this->relations[$path];
	}

	/**
	 * @return list<RepresentationRelationBinding>
	 */
	public function getRelations(): array
	{
		return array_values($this->relations);
	}

	public function hasPath(string $path): bool
	{
		return $this->hasField($path) || $this->hasExpression($path) || $this->hasRelation($path);
	}

	/**
	 * @return list<string>
	 */
	public function getPaths(): array
	{
		return $this->paths;
	}

	public function applyToRecordState(RecordState $state): self
	{
		$applied = new self();
		foreach ($this->getFields() as $binding) {
			$field = $binding->getField();
			if (! $field->isTemplate()) {
				throw new StateException(sprintf("Representation binding path '%s' already targets a concrete record.", $binding->getPath()));
			}

			if ($field->getCollectionName() !== $state->getCollectionName()) {
				throw new StateException(sprintf(
					"Representation binding path '%s' targets collection '%s', not '%s'.",
					$binding->getPath(),
					$field->getCollectionName(),
					$state->getCollectionName()
				));
			}

			$applied->addField($binding->withField(RecordFieldRef::forState($state, $field->getFieldName())));
		}

		foreach ($this->getExpressions() as $binding) {
			$applied->addExpression($binding);
		}

		foreach ($this->getRelations() as $binding) {
			$applied->addRelation($binding);
		}

		return $applied;
	}

	private function assertPathIsAvailable(string $path): void
	{
		if ($this->hasPath($path)) {
			throw new StateException(sprintf("Representation binding already contains path '%s'.", $path));
		}
	}
}
