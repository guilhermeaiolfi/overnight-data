<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class ManualProjectionPathResolver
{
	public function __construct(
		private RepresentationStore $representations,
	) {
	}

	public function resolve(object $owner, string $path): ManualProjectionPathResolution
	{
		$ownerState = $this->representations->get($owner);
		if (! $ownerState instanceof RepresentationState) {
			throw new SyncException('Cannot use fromPath() because the owner representation is not tracked.');
		}

		$relationBinding = $this->relationBindingFromPath($ownerState->getBinding(), $path);
		$relation = $relationBinding->getRelation();
		if (! $relation->hasState()) {
			throw new StateException(sprintf("Cannot use fromPath('%s') because the owner relation binding is not bound to a concrete record state.", $path));
		}

		return new ManualProjectionPathResolution(
			$owner,
			$relation->getState(),
			$path,
			$relationBinding->getRelationName(),
			$relationBinding->getCardinality(),
			$relationBinding->getRelatedBinding()
		);
	}

	public function collectionFromBinding(RepresentationBinding $binding): CollectionInterface
	{
		foreach ($binding->getFields() as $fieldBinding) {
			return $fieldBinding->getField()->getCollection();
		}

		foreach ($binding->getRelations() as $relationBinding) {
			return $relationBinding->getRelation()->getCollection();
		}

		throw new StateException('Cannot resolve relation target collection from an empty related binding.');
	}

	private function relationBindingFromPath(RepresentationBinding $binding, string $path): RepresentationRelationBinding
	{
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === []) {
			throw new InvalidArgumentException('ManualProjectionBuilder::fromPath() requires a non-empty path.');
		}

		$current = $binding;
		$relation = null;
		$lastIndex = count($segments) - 1;
		foreach ($segments as $index => $segment) {
			if (! $current->hasRelation($segment)) {
				if ($current->hasField($segment) || $current->hasExpression($segment)) {
					throw new StateException(sprintf("Cannot use fromPath('%s') because path segment '%s' is a scalar projection path, not a relation.", $path, $segment));
				}

				throw new StateException(sprintf("Cannot use fromPath('%s') because relation path segment '%s' is not bound.", $path, $segment));
			}

			$relation = $current->getRelation($segment);
			if ($relation->getCardinality() === RepresentationRelationCardinality::MANY && $index !== $lastIndex) {
				throw new StateException(sprintf("Cannot use fromPath('%s') through MANY relation segment '%s' without a concrete relation item.", $path, $segment));
			}

			$current = $relation->getRelatedBinding();
		}

		return $relation;
	}
}
