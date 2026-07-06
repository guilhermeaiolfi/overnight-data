<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use InvalidArgumentException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Selection\SelectionItem;

final class SelectionProjectionCompiler
{
	/**
	 * @param list<SelectionItem> $selections
	 * @param callable(SelectionItem, FieldRef): ?RecordFieldRef $fieldResolver
	 */
	public function compile(
		array $selections,
		callable $fieldResolver,
		bool $skipWhenMissing = false,
		bool $ignoreUnsupported = true,
	): RepresentationBinding {
		$binding = new RepresentationBinding();
		$this->compileInto($binding, $selections, $fieldResolver, $skipWhenMissing, $ignoreUnsupported);

		return $binding;
	}

	/**
	 * @param list<SelectionItem> $selections
	 * @param callable(SelectionItem, FieldRef): ?RecordFieldRef $fieldResolver
	 */
	public function compileInto(
		RepresentationBinding $binding,
		array $selections,
		callable $fieldResolver,
		bool $skipWhenMissing = false,
		bool $ignoreUnsupported = true,
	): void {
		foreach ($selections as $selection) {
			$fieldRef = $this->fieldRefFrom($selection->getExpression(), $ignoreUnsupported);
			if (! $fieldRef instanceof FieldRef) {
				continue;
			}

			$field = $fieldResolver($selection, $fieldRef);
			if (! $field instanceof RecordFieldRef) {
				continue;
			}

			$path = $this->selectionPath($selection);
			if ($binding->hasField($path)) {
				continue;
			}

			$binding->addField(new RepresentationFieldBinding(
				$path,
				$field,
				writable: true,
				skipWhenMissing: $skipWhenMissing
			));
		}
	}

	private function fieldRefFrom(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		bool $ignoreUnsupported,
	): ?FieldRef {
		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if ($expression instanceof FieldRef) {
			return $expression;
		}

		if ($ignoreUnsupported) {
			return null;
		}

		throw new InvalidArgumentException('Projection selections only support FieldRef expressions in this context.');
	}

	private function selectionPath(SelectionItem $selection): string
	{
		$expression = $selection->getExpression();
		if ($expression instanceof AliasedExpression) {
			return $expression->getAlias();
		}

		return $selection->getSelectionKey();
	}
}
