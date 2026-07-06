<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

use ON\Data\ORM\Compiler\ManualProjection\ManualProjectionSourceResolver;
use ON\Data\ORM\State\RepresentationBinding;

final class ManualProjectionBindingCompiler
{
	public function __construct(
		private ProjectionBindingAssembler $bindingAssembler = new ProjectionBindingAssembler(),
		private ManualProjectionSourceResolver $sourceResolver = new ManualProjectionSourceResolver(),
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
