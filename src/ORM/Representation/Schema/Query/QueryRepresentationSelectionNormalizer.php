<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use InvalidArgumentException;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Selection\SelectionItem;
/**
 * Converts query SelectionItem / expression nodes into RepresentationFieldShape
 * values (public path, source, field name).
 *
 * Exists to isolate query-expression parsing from schema assembly so manual
 * projections can build shapes directly without touching selection types.
 */
final class QueryRepresentationSelectionNormalizer
{
	/**
	 * @param list<SelectionItem> $selections
	 * @return list<RepresentationFieldShape>
	 */
	public function normalizeSelections(array $selections, bool $ignoreUnsupported = true): array
	{
		$shapes = [];

		foreach ($selections as $selection) {
			$shape = $this->shapeFromSelection($selection, $ignoreUnsupported);
			if ($shape instanceof RepresentationFieldShape) {
				$shapes[] = $shape;
			}
		}

		return $shapes;
	}

	private function shapeFromSelection(SelectionItem $selection, bool $ignoreUnsupported): ?RepresentationFieldShape
	{
		$expression = $selection->getExpression();
		$publicPath = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$publicPath = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			if ($ignoreUnsupported) {
				return null;
			}

			throw new InvalidArgumentException('Projection selections only support FieldRef expressions in this context.');
		}

		return new RepresentationFieldShape(
			$publicPath,
			$expression->getSource(),
			$expression->getName(),
		);
	}
}
