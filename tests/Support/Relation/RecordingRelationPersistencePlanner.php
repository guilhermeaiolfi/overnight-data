<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support\Relation;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\RelationPersistencePlannerInterface;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\ToManyRelationState;

final class RecordingRelationPersistencePlanner implements RelationPersistencePlannerInterface
{
	public static int $calls = 0;

	/** @var list<PersistenceContext> */
	public static array $contexts = [];

	/** @var list<RelationInterface> */
	public static array $relations = [];

	/** @var list<ToManyRelationState> */
	public static array $collections = [];

	/** @var list<RelationChangeInterface> */
	public static array $changes = [];

	public static bool $addCommand = false;

	public static ?string $mutateOwnerField = null;

	public static mixed $mutateOwnerValue = null;

	/** @var list<mixed> */
	public static array $observedOwnerValues = [];

	public static function reset(): void
	{
		self::$calls = 0;
		self::$contexts = [];
		self::$relations = [];
		self::$collections = [];
		self::$changes = [];
		self::$addCommand = false;
		self::$mutateOwnerField = null;
		self::$mutateOwnerValue = null;
		self::$observedOwnerValues = [];
	}

	public function plan(PersistenceContext $context, RelationInterface $relation, RelationChangeInterface $change): void
	{
		++self::$calls;
		self::$contexts[] = $context;
		self::$relations[] = $relation;
		self::$changes[] = $change;

		if ($change instanceof ToManyRelationState) {
			self::$collections[] = $change;
		}

		if ($change->getOwner()->hasValue('name')) {
			self::$observedOwnerValues[] = $change->getOwner()->getValue('name');
		}

		if (self::$mutateOwnerField !== null) {
			$change->getOwner()->setValue(self::$mutateOwnerField, self::$mutateOwnerValue);
		}

		if (self::$addCommand) {
			$context->getCommands()->add(new TestCommand($change->getOwner()->getCollection()));
		}
	}
}
