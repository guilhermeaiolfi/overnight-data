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
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\State\RepresentationBinding;

final class ProjectionCompiler
{
	public function __construct(
		private ProjectionBindingAssembler $bindingAssembler = new ProjectionBindingAssembler(),
		private SourceResolver $sourceResolver = new SourceResolver(),
	) {
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function compile(array $propertyShapes): RepresentationBinding
	{
		if ($propertyShapes === []) {
			return new RepresentationBinding();
		}

		return $this->bindingAssembler->assemble(
			$propertyShapes,
			$this->sourceResolver,
			skipWhenMissing: true,
		);
	}
}
