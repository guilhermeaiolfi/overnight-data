<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use InvalidArgumentException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Selection\SelectionItem;

final class ProjectionSelectionNormalizer
{
	/**
	 * @param list<SelectionItem> $selections
	 * @return list<ProjectionFieldShape>
	 */
	public function normalizeSelections(array $selections, bool $ignoreUnsupported = true): array
	{
		$shapes = [];

		foreach ($selections as $selection) {
			$shape = $this->shapeFromSelection($selection, $ignoreUnsupported);
			if ($shape instanceof ProjectionFieldShape) {
				$shapes[] = $shape;
			}
		}

		return $shapes;
	}

	/**
	 * @param list<ValueExpressionInterface|AliasedExpression|StarExpression> $expressions
	 * @return list<ProjectionFieldShape>
	 */
	public function normalizeExpressions(array $expressions, bool $ignoreUnsupported = true): array
	{
		$selections = [];
		foreach ($expressions as $expression) {
			$selections[] = new SelectionItem($expression, explicit: true);
		}

		return $this->normalizeSelections($selections, $ignoreUnsupported);
	}

	private function shapeFromSelection(SelectionItem $selection, bool $ignoreUnsupported): ?ProjectionFieldShape
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

		return new ProjectionFieldShape(
			$publicPath,
			$expression->getSource(),
			$expression->getName(),
			$expression,
		);
	}
}
