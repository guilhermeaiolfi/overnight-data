<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use InvalidArgumentException;
use ON\Data\Definition\Exception\RelationException;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class RelationPersistenceSynchronizer
{
	public function sync(
		RelatedCollectionMap $relations,
		RecordStateMap $records,
		TrackedRepresentationMap $representations,
	): RelationPersistenceResult {
		$changed = $relations->getChanged();
		$commands = new CommandBuffer();
		$context = new PersistenceContext($records, $representations, $relations, $commands);

		foreach ($changed as $collection) {
			$relation = $this->resolveRelation($collection);
			try {
				$plannerClass = $relation->getPersistencePlanner();
			} catch (InvalidArgumentException $exception) {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' has an invalid persistence planner.",
					$collection->getRelationName()
				), 0, $exception);
			}

			if ($plannerClass === null) {
				throw new RelationPersistenceException(sprintf(
					"Changed relation collection '%s' has no configured persistence planner.",
					$collection->getRelationName()
				));
			}

			$planner = new $plannerClass();
			if (! $planner instanceof RelationPersistencePlannerInterface) {
				throw new RelationPersistenceException(sprintf(
					"Relation persistence planner '%s' must implement %s.",
					$plannerClass,
					RelationPersistencePlannerInterface::class
				));
			}

			$planner->plan($context, $relation, $collection);
		}

		return new RelationPersistenceResult($changed, $commands->getAll());
	}

	private function resolveRelation(RelatedCollection $collection): RelationInterface
	{
		$relations = $collection->getOwner()->getCollection()->getRelations();
		if (! $relations->has($collection->getRelationName())) {
			throw new RelationPersistenceException(sprintf(
				"Changed relation collection '%s' has no relation definition.",
				$collection->getRelationName()
			));
		}

		try {
			return $relations->get($collection->getRelationName());
		} catch (RelationException $exception) {
			throw new RelationPersistenceException(sprintf(
				"Changed relation collection '%s' has no relation definition.",
				$collection->getRelationName()
			), 0, $exception);
		}
	}
}
