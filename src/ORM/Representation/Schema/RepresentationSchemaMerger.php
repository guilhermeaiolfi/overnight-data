<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

use ON\Data\ORM\Exception\StateException;
/**
 * Merges a manual projection overlay into an existing tracked schema.
 *
 * Exists so Builder::end() can add manual field paths on top of query-created
 * provenance without recompiling the full schema graph.
 */
final class RepresentationSchemaMerger
{
	public function mergeManualOverlay(RepresentationSchema $existing, RepresentationSchema $manual): RepresentationSchema
	{
		$merged = new RepresentationSchema($existing->getCollection());
		foreach ($existing->getFields() as $field) {
			$merged->addField($field);
		}
		foreach ($existing->getRelations() as $relation) {
			$merged->addRelation($relation);
		}

		foreach ($manual->getFields() as $field) {
			if ($merged->hasPath($field->getPath())) {
				throw new StateException(sprintf("Manual projection path '%s' conflicts with an existing representation schema path.", $field->getPath()));
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
