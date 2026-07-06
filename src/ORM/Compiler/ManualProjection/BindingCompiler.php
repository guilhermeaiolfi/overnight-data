<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\State\RepresentationBinding;

final class BindingCompiler
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
