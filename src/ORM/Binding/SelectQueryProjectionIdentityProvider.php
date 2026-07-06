<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Query\ProjectionIdentityMap;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

final class SelectQueryProjectionIdentityProvider implements ProjectionIdentityProviderInterface
{
	public function __construct(
		private SelectQuery $query,
		private ProjectionIdentityMap $projectionIdentities,
		private ?CollectionInterface $targetCollection = null,
	) {
	}

	public function fieldForSelection(SelectionItem $selection, FieldRef $fieldRef): ?RecordFieldRef
	{
		$collection = $this->targetCollection ?? $this->collectionFor($selection);
		if (! $collection instanceof CollectionInterface) {
			return null;
		}

		return RecordFieldRef::template($collection, $fieldRef->getName());
	}

	public function getProjectionIdentities(): ProjectionIdentityMap
	{
		return $this->projectionIdentities;
	}

	private function collectionFor(SelectionItem $selection): ?CollectionInterface
	{
		$expression = $selection->getExpression();
		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return null;
		}

		$source = $expression->getSource();
		if ($source === $this->query) {
			return $this->query->getCollection();
		}

		if ($source instanceof RelationRef && $source->getQuery() === $this->query) {
			return $source->getCollection();
		}

		return null;
	}
}
