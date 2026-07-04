<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support\Relation;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\RelationPersistencePlannerInterface;
use ON\Data\ORM\Relation\RelatedCollection;

final class RecordingRelationPersistencePlanner implements RelationPersistencePlannerInterface
{
	public static int $calls = 0;

	/** @var list<PersistenceContext> */
	public static array $contexts = [];

	/** @var list<RelationInterface> */
	public static array $relations = [];

	/** @var list<RelatedCollection> */
	public static array $collections = [];

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
		self::$addCommand = false;
		self::$mutateOwnerField = null;
		self::$mutateOwnerValue = null;
		self::$observedOwnerValues = [];
	}

	public function plan(PersistenceContext $context, RelationInterface $relation, RelatedCollection $collection): void
	{
		++self::$calls;
		self::$contexts[] = $context;
		self::$relations[] = $relation;
		self::$collections[] = $collection;

		if ($collection->getOwner()->hasValue('name')) {
			self::$observedOwnerValues[] = $collection->getOwner()->getValue('name');
		}

		if (self::$mutateOwnerField !== null) {
			$collection->getOwner()->setValue(self::$mutateOwnerField, self::$mutateOwnerValue);
		}

		if (self::$addCommand) {
			$context->getCommands()->add(new TestCommand($collection->getOwner()->getCollection()));
		}
	}
}
