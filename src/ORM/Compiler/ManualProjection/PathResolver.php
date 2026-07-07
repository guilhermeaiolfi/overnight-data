<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Resolves Builder::fromPath() against an already-tracked owner binding graph.
 *
 * Exists to walk RepresentationRelationBinding branches on tracked owners and
 * return the related binding template used when creating relation targets.
 */
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class PathResolver
{
	public function __construct(
		private RepresentationStore $representations,
	) {
	}

	public function resolve(object $owner, string $path): PathResolution
	{
		$ownerState = $this->representations->get($owner);
		if (! $ownerState instanceof RepresentationState) {
			throw new SyncException('Cannot use fromPath() because the owner representation is not tracked.');
		}

		$relationBinding = $this->relationBindingFromPath($ownerState->getBinding(), $path);
		if (! $ownerState->hasRelationItem($relationBinding->getPath())) {
			throw new StateException(sprintf("Cannot use fromPath('%s') because the owner relation path is not attached to a concrete record state.", $path));
		}
		$relationItem = $ownerState->getRelationItem($relationBinding->getPath());

		return new PathResolution(
			$owner,
			$relationItem->getOwnerRecord(),
			$path,
			$relationBinding->getRelationName(),
			$relationBinding->isMany() ? RepresentationRelationCardinality::MANY : RepresentationRelationCardinality::ONE,
			$relationBinding->getRelatedBinding()
		);
	}

	public function collectionFromBinding(RepresentationBinding $binding): CollectionInterface
	{
		foreach ($binding->getFields() as $fieldBinding) {
			return $fieldBinding->getCollection();
		}

		foreach ($binding->getRelations() as $relationBinding) {
			return $relationBinding->getOwnerCollection();
		}

		throw new StateException('Cannot resolve relation target collection from an empty related binding.');
	}

	private function relationBindingFromPath(RepresentationBinding $binding, string $path): RepresentationRelationBinding
	{
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === []) {
			throw new InvalidArgumentException('Builder::fromPath() requires a non-empty path.');
		}

		$current = $binding;
		$relation = null;
		$lastIndex = count($segments) - 1;
		foreach ($segments as $index => $segment) {
			if (! $current->hasRelation($segment)) {
				if ($current->hasField($segment)) {
					throw new StateException(sprintf("Cannot use fromPath('%s') because path segment '%s' is a scalar projection path, not a relation.", $path, $segment));
				}

				throw new StateException(sprintf("Cannot use fromPath('%s') because relation path segment '%s' is not bound.", $path, $segment));
			}

			$relation = $current->getRelation($segment);
			if ($relation->isMany() && $index !== $lastIndex) {
				throw new StateException(sprintf("Cannot use fromPath('%s') through MANY relation segment '%s' without a concrete relation item.", $path, $segment));
			}

			$current = $relation->getRelatedBinding();
		}

		return $relation;
	}
}
