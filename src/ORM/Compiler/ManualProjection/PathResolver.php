<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Resolves Builder::fromPath() against an already-tracked owner binding graph.
 *
 * Exists to walk RepresentationRelationSchema branches on tracked owners and
 * return the related binding template used when creating relation targets.
 */
use InvalidArgumentException;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class PathResolver
{
	public function __construct(
		private RepresentationStateStore $representations,
	) {
	}

	public function resolve(object $owner, string $path): PathResolution
	{
		$ownerState = $this->representations->get($owner);
		if (! $ownerState instanceof RepresentationState) {
			throw new SyncException('Cannot use fromPath() because the owner representation is not tracked.');
		}

		$relationSchema = $this->relationSchemaFromPath($ownerState->getSchema(), $path);
		if (! $ownerState->hasRelationItem($relationSchema->getPath())) {
			throw new StateException(sprintf("Cannot use fromPath('%s') because the owner relation path is not attached to a concrete record state.", $path));
		}
		$relationItem = $ownerState->getRelationItem($relationSchema->getPath());

		return new PathResolution(
			$owner,
			$relationItem->getOwnerRecord(),
			$relationSchema->getRelationName(),
			$relationSchema->isMany() ? RepresentationRelationCardinality::MANY : RepresentationRelationCardinality::ONE,
			$relationSchema->getRelatedSchema()
		);
	}

	private function relationSchemaFromPath(RepresentationSchema $binding, string $path): RepresentationRelationSchema
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

			$current = $relation->getRelatedSchema();
		}

		return $relation;
	}
}
