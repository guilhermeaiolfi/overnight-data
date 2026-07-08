<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Builds structural projection sources from an assembled representation schema.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationFieldSchema;

final class ProjectionSourceBuilder
{
	/**
	 * @return list<ProjectionSource>
	 */
	public function build(RepresentationSchema $binding): array
	{
		/** @var array<string, list<RepresentationFieldSchema>> $fieldsByPath */
		$fieldsByPath = [];
		/** @var array<string, list<string>> $pathsByKey */
		$pathsByKey = [];
		/** @var array<string, CollectionInterface> $collectionsByKey */
		$collectionsByKey = [];

		foreach ($binding->getFields() as $field) {
			$key = $field->getSourcePathKey();
			$fieldsByPath[$key][] = $field;
			$pathsByKey[$key] ??= $field->getSourcePath();
			$collectionsByKey[$key] ??= $field->getCollection();
		}

		$sources = [];
		foreach ($fieldsByPath as $key => $fields) {
			$sources[] = new ProjectionSource($pathsByKey[$key], $collectionsByKey[$key], $fields);
		}

		return $sources;
	}
}
