<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

/**
 * Merges a manual projection overlay into an existing tracked binding.
 *
 * Exists so Builder::end() can add manual field paths on top of query-created
 * provenance without recompiling the full binding graph.
 */
use ON\Data\ORM\Exception\StateException;

final class RepresentationBindingMerger
{
	public function mergeManualOverlay(RepresentationBinding $existing, RepresentationBinding $manual): RepresentationBinding
	{
		$merged = new RepresentationBinding($existing->getCollection());
		foreach ($existing->getFields() as $field) {
			$merged->addField($field);
		}
		foreach ($existing->getRelations() as $relation) {
			$merged->addRelation($relation);
		}

		foreach ($manual->getFields() as $field) {
			if ($merged->hasPath($field->getPath())) {
				throw new StateException(sprintf("Manual projection path '%s' conflicts with an existing representation binding path.", $field->getPath()));
			}

			$merged->addField($field);
		}

		foreach ($manual->getRelations() as $relation) {
			if ($merged->hasPath($relation->getPath())) {
				continue;
			}

			$merged->addRelation($relation);
		}

		return $merged;
	}
}
