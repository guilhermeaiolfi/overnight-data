<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSchemaAssembler;

/**
 * Manual representation compiler: assembles RepresentationSchema from pre-built
 * RepresentationFieldShape values using the manual ManualRepresentationSourceResolver.
 *
 * Exists as the thin manual counterpart to the query-side projection compiler; it
 * reuses RepresentationSchemaAssembler and enables skip-when-missing for overlays.
 */
final class ManualRepresentationSchemaCompiler
{
	public function __construct(
		private RepresentationSchemaAssembler $schemaAssembler = new RepresentationSchemaAssembler(),
		private ManualRepresentationSourceResolver $sourceResolver = new ManualRepresentationSourceResolver(),
	) {
	}

	/**
	 * The root collection is derived from the first declared property source, so
	 * source resolution errors (for example selecting a MANY relation without a
	 * concrete item) surface here. When there are no declared properties the
	 * fallback collection (the existing tracked schema root) is used instead.
	 *
	 * @param list<RepresentationFieldShape> $propertyShapes
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
