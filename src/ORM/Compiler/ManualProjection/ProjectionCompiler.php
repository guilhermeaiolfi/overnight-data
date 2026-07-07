<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Manual projection compiler: assembles RepresentationBinding from pre-built
 * ProjectionFieldShape values using the manual SourceResolver.
 *
 * Exists as the thin manual counterpart to the query-side projection compiler; it
 * reuses ProjectionBindingAssembler and enables skip-when-missing for overlays.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;

final class ProjectionCompiler
{
	public function __construct(
		private ProjectionBindingAssembler $bindingAssembler = new ProjectionBindingAssembler(),
		private SourceResolver $sourceResolver = new SourceResolver(),
	) {
	}

	/**
	 * The root collection is derived from the first declared property source, so
	 * source resolution errors (for example selecting a MANY relation without a
	 * concrete item) surface here. When there are no declared properties the
	 * fallback collection (the existing tracked binding root) is used instead.
	 *
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function compile(array $propertyShapes, ?CollectionInterface $fallbackCollection = null): RepresentationBinding
	{
		if ($propertyShapes === []) {
			if (! $fallbackCollection instanceof CollectionInterface) {
				throw new StateException('Cannot compile a manual projection without a tracked representation or at least one property declaration.');
			}

			return new RepresentationBinding($fallbackCollection);
		}

		$rootCollection = $this->sourceResolver->resolve($propertyShapes[0]->getSource())->getCollection();

		return $this->bindingAssembler->assemble(
			$propertyShapes,
			$this->sourceResolver,
			$rootCollection,
			skipWhenMissing: true,
		);
	}
}
