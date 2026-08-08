<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Projection\ArrayProjectionLayout;
use ON\Data\Query\Projection\ProjectionLayout;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Result\WritablePreparation;

/**
 * Writable prepare() result: schema, sources, and the query-scoped
 * {@see QuerySourceIdentities} map (locators for the whole fetch).
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can hold the
 * plan without importing ORM adoption types into the query layer.
 *
 * Query-owned {@see ProjectionLayout} is derived from the place schema so
 * LoadRuntime stays ORM-free (proposal 0003 / boundary fix).
 */
final class QueryRepresentationPlan implements WritablePreparation
{
	/** @var list<RepresentationSource> */
	private array $sources;

	/** @var array<string, QuerySourceIdentities> */
	private array $relationIdentities = [];

	private ProjectionLayout $layout;

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function __construct(
		private RepresentationSchema $schema,
		array $sources,
		private QuerySourceIdentities $identities,
	) {
		$this->sources = array_values($sources);
		$this->layout = self::layoutFromSchema($schema);
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	public function getProjectionLayout(): ?ProjectionLayout
	{
		return $this->layout;
	}

	/**
	 * Flat place keys at each relation path, for Query assemble without ORM types.
	 */
	public static function layoutFromSchema(RepresentationSchema $schema): ProjectionLayout
	{
		/** @var array<string, list<string>> $flatPlaceKeysByPathKey */
		$flatPlaceKeysByPathKey = [];

		$walk = static function (RepresentationSchema $node, array $path) use (&$flatPlaceKeysByPathKey, &$walk): void {
			$keys = [];

			foreach ($node->getFields() as $field) {
				if ($field->getSourcePath() === []) {
					continue;
				}

				$keys[] = $field->getPath();
			}

			$flatPlaceKeysByPathKey[RelationSelection::pathKey($path)] = $keys;

			foreach ($node->getRelations() as $relation) {
				$walk(
					$relation->getRelatedSchema(),
					[...$path, $relation->getRelationName()],
				);
			}
		};

		$walk($schema, []);

		return new ArrayProjectionLayout($flatPlaceKeysByPathKey);
	}

	/**
	 * @return list<RepresentationSource>
	 */
	public function getSources(): array
	{
		return $this->sources;
	}

	/**
	 * One identity map for the prepared query root; reuse across all tracked rows.
	 */
	public function getIdentities(): QuerySourceIdentities
	{
		return $this->identities;
	}

	/**
	 * @param list<string> $relationPath
	 */
	public function setRelationIdentities(array $relationPath, QuerySourceIdentities $identities): void
	{
		$this->relationIdentities[$this->pathKey($relationPath)] = $identities;
	}

	/**
	 * @param list<string> $relationPath
	 */
	public function getRelationIdentities(array $relationPath): ?QuerySourceIdentities
	{
		return $this->relationIdentities[$this->pathKey($relationPath)] ?? null;
	}

	/**
	 * @param list<string> $path
	 */
	private function pathKey(array $path): string
	{
		return RelationSelection::pathKey(array_values($path));
	}
}
