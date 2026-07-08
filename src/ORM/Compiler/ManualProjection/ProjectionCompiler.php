<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Manual projection compiler: assembles RepresentationSchema from pre-built
 * ProjectionFieldShape values using the manual SourceResolver.
 *
 * Exists as the thin manual counterpart to the query-side projection compiler; it
 * reuses ProjectionSchemaAssembler and enables skip-when-missing for overlays.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Compiler\ProjectionSchemaAssembler;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationSchema;

final class ProjectionCompiler
{
	public function __construct(
		private ProjectionSchemaAssembler $schemaAssembler = new ProjectionSchemaAssembler(),
		private SourceResolver $sourceResolver = new SourceResolver(),
	) {
	}

	/**
	 * The root collection is derived from the first declared property source, so
	 * source resolution errors (for example selecting a MANY relation without a
	 * concrete item) surface here. When there are no declared properties the
	 * fallback collection (the existing tracked schema root) is used instead.
	 *
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function compile(array $propertyShapes, ?CollectionInterface $fallbackCollection = null): RepresentationSchema
	{
		if ($propertyShapes === []) {
			if (! $fallbackCollection instanceof CollectionInterface) {
				throw new StateException('Cannot compile a manual projection without a tracked representation or at least one property declaration.');
			}

			return new RepresentationSchema($fallbackCollection);
		}

		$rootCollection = $this->sourceResolver->resolve($propertyShapes[0]->getSource())->getCollection();

		return $this->schemaAssembler->assemble(
			$propertyShapes,
			$this->sourceResolver,
			$rootCollection,
			skipWhenMissing: true,
		);
	}
}
