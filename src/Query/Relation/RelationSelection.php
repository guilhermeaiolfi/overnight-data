<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Sort\Sort;

final class RelationSelection
{
	public function __construct(
		private readonly RelationRef $relationRef,
		private readonly bool $load,
		private readonly bool $visible,
		private readonly ?array $fields,
		private readonly array $conditions = [],
		private readonly array $sorts = [],
		private readonly ?LoadStrategy $strategy = null,
	) {
	}

	public function getRelationRef(): RelationRef
	{
		return $this->relationRef;
	}

	public function getName(): string
	{
		return $this->relationRef->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->relationRef->getPath();
	}

	public function getParentPathKey(): ?string
	{
		$path = $this->getPath();

		if (count($path) === 1) {
			return null;
		}

		array_pop($path);

		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	public function isLoaded(): bool
	{
		return $this->load;
	}

	public function isVisible(): bool
	{
		return $this->visible;
	}

	public function getFields(): ?array
	{
		return $this->fields;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/**
	 * @return list<Sort>
	 */
	public function getSorts(): array
	{
		return $this->sorts;
	}

	public function getStrategy(): ?LoadStrategy
	{
		return $this->strategy;
	}

	public function merge(self $incoming): self
	{
		$sameRelationRef = $this->relationRef === $incoming->relationRef;
		$load = $this->load || $incoming->load;
		$visible = $this->visible || $incoming->visible || $load;
		$fields = $this->mergeFields($incoming);
		$conditions = $sameRelationRef ? $incoming->conditions : [...$this->conditions, ...$incoming->conditions];
		$sorts = $sameRelationRef ? $incoming->sorts : [...$this->sorts, ...$incoming->sorts];
		$strategy = $this->mergeStrategy($incoming);

		if (
			$load === $this->load
			&& $visible === $this->visible
			&& $fields === $this->fields
			&& $conditions === $this->conditions
			&& $sorts === $this->sorts
			&& $strategy === $this->strategy
		) {
			return $this;
		}

		return new self($this->relationRef, $load, $visible, $fields, $conditions, $sorts, $strategy);
	}

	private function mergeFields(self $incoming): ?array
	{
		if ($this->fields === null || $incoming->fields === null) {
			return null;
		}

		$merged = $this->fields;
		$seen = array_fill_keys($merged, true);

		foreach ($incoming->fields as $fieldName) {
			if (isset($seen[$fieldName])) {
				continue;
			}

			$seen[$fieldName] = true;
			$merged[] = $fieldName;
		}

		return $merged;
	}

	private function mergeStrategy(self $incoming): ?LoadStrategy
	{
		return $incoming->strategy ?? $this->strategy;
	}
}
