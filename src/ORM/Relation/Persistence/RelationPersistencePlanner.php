<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use InvalidArgumentException;
use ON\Data\Definition\Exception\RelationException;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class RelationPersistencePlanner
{
	/**
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	public function plan(
		RelationStateStore $toManyRelations,
		RelationStateStore $toOneRelations,
		RecordStateStore $records,
		RepresentationStore $representations,
	): RelationPersistenceResult {
		$changed = array_merge($toManyRelations->getChanged(), $toOneRelations->getChanged());
		$commands = new CommandBuffer();
		$context = new PersistenceContext(
			$records,
			$representations,
			$toManyRelations,
			$toOneRelations,
			$commands
		);

		foreach ($changed as $change) {
			$relation = $this->resolveRelation($change);

			try {
				$plannerClass = $relation->getPersistencePlanner();
			} catch (InvalidArgumentException $exception) {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' has an invalid persistence planner.",
					$change->getRelationName()
				), 0, $exception);
			}

			if ($plannerClass === null) {
				throw new RelationPersistenceException(sprintf(
					"Changed relation '%s' has no configured persistence planner.",
					$change->getRelationName()
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

			$planner->plan($context, $relation, $change);
		}

		return new RelationPersistenceResult($changed, $commands->getAll());
	}

	private function resolveRelation(RelationChangeInterface $change): RelationInterface
	{
		$relations = $change->getOwner()->getCollection()->getRelations();
		if (! $relations->has($change->getRelationName())) {
			throw new RelationPersistenceException(sprintf(
				"Changed relation '%s' has no relation definition.",
				$change->getRelationName()
			));
		}

		try {
			return $relations->get($change->getRelationName());
		} catch (RelationException $exception) {
			throw new RelationPersistenceException(sprintf(
				"Changed relation '%s' has no relation definition.",
				$change->getRelationName()
			), 0, $exception);
		}
	}
}
