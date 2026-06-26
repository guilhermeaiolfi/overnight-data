<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

final class RelationSelection
{
	public function __construct(
		private readonly RelationRef $relation,
		private readonly bool $load,
		private readonly bool $visible,
		private readonly ?array $fields,
	) {
	}

	public function getRelation(): RelationRef
	{
		return $this->relation;
	}

	public function getName(): string
	{
		return $this->relation->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->relation->getPath();
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

	public function merge(self $incoming): self
	{
		$load = $this->load || $incoming->load;
		$visible = $this->visible || $incoming->visible || $load;
		$fields = $this->mergeFields($incoming);

		if ($load === $this->load && $visible === $this->visible && $fields === $this->fields) {
			return $this;
		}

		return new self($this->relation, $load, $visible, $fields);
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
}
