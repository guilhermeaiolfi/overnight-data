<?php

declare(strict_types=1);

namespace ON\Data\Query\Load;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\SelectQuery;

/**
 * Derives a read-only {@see LoadGraph} from a {@see SelectQuery} (Phase 0 of proposal 0003).
 *
 * Does not mutate the query or change fetch behavior.
 */
final class LoadGraphBuilder
{
	public function fromQuery(SelectQuery $query): LoadGraph
	{
		$graph = new LoadGraph();
		$from = $query->getFrom();

		if (! $from instanceof CollectionInterface) {
			return $graph;
		}

		$root = new LoadGraphNode([], $from, loaded: true, visible: true);
		$graph->add($root);
		$this->collectSelections($graph, $query->getSelections(), $query);

		foreach ($query->getRelationSelections()->getAll() as $selection) {
			$this->collectRelationSelection($graph, $selection);
		}

		return $graph;
	}

	private function collectRelationSelection(LoadGraph $graph, RelationSelection $selection): void
	{
		$ref = $selection->getRelationRef();
		$node = $this->ensureNode($graph, $ref->getPath(), $ref->getCollection());
		$node->markLoaded($selection->isLoaded());
		$node->markVisible($selection->isVisible());
		$node->setStrategy($selection->getStrategy());

		if ($selection->hasDefaultSelection()) {
			$node->markDefaultFields(true);

			return;
		}

		$this->collectSelections($graph, $selection->getSelections(), $ref->getQuery());
	}

	private function collectSelections(
		LoadGraph $graph,
		SelectionList $selections,
		SelectQuery $query,
	): void {
		foreach ($selections->getAll() as $item) {
			$expression = $item->getExpression();

			if ($expression instanceof AliasedExpression) {
				$expression = $expression->getExpression();
			}

			if ($expression instanceof StarExpression) {
				$source = $expression->getSource();

				if ($source instanceof RelationRef) {
					$node = $this->ensureNode($graph, $source->getPath(), $source->getCollection());
					$node->markDefaultFields(true);
				} elseif ($source === $query || $source === $query->getFrom()) {
					$root = $graph->get([]);
					$root?->markDefaultFields(true);
				}

				continue;
			}

			if (! $expression instanceof FieldRef) {
				continue;
			}

			$source = $expression->getSource();

			if ($source instanceof RelationRef) {
				if ($source->getQuery() !== $query) {
					continue;
				}

				$node = $this->ensureNode($graph, $source->getPath(), $source->getCollection());
				$node->addField($expression->getField()->getName());

				continue;
			}

			if ($source === $query || $source === $query->getFrom()) {
				$graph->get([])?->addField($expression->getField()->getName());
			}
		}
	}

	/**
	 * @param list<string> $path
	 */
	private function ensureNode(LoadGraph $graph, array $path, CollectionInterface $collection): LoadGraphNode
	{
		$existing = $graph->get($path);

		if ($existing instanceof LoadGraphNode) {
			return $existing;
		}

		$node = new LoadGraphNode($path, $collection);
		$graph->add($node);

		return $node;
	}
}
