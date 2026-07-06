<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\State\RecordState;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;

final class RootTarget implements PropertySource
{
	public function __construct(
		private RecordState $targetRecord,
	) {
	}

	public function getTargetRecord(): RecordState
	{
		return $this->targetRecord;
	}

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array
	{
		return [];
	}

	public function field(string $name): PropertyRef
	{
		$collection = $this->targetRecord->getCollection();
		if (! $collection->hasField($name)) {
			throw new UnknownQueryFieldException(sprintf("Unknown field '%s' on collection '%s'.", $name, $collection->getName()));
		}

		return new PropertyRef($this, $name);
	}

	public function all(): AllProperties
	{
		return new AllProperties($this);
	}

	public function __get(string $name): PropertyRef|RelationRef
	{
		$collection = $this->targetRecord->getCollection();
		if ($collection->hasRelation($name)) {
			$definition = $collection->getRelation($name);
			if ($definition === null) {
				throw new UnknownQueryRelationException(sprintf("Unknown relation '%s' on collection '%s'.", $name, $collection->getName()));
			}

			return new RelationRef($this, $name, $definition);
		}

		if ($collection->hasField($name)) {
			return $this->field($name);
		}

		throw new UnknownQueryMemberException(sprintf("Unknown member '%s' on collection '%s'.", $name, $collection->getName()));
	}
}
