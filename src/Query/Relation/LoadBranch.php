<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

abstract class LoadBranch
{
	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	/**
	 * @var list<RelationLoadBranch>
	 */
	private array $children = [];

	/**
	 * @var array<string, true>
	 */
	private array $requiredFieldNames = [];

	private ?RepresentationSchema $representationSchema = null;

	private ?FetchMapping $fetchMapping = null;

	/**
	 * Distinct records loaded for this branch, in first-seen order.
	 *
	 * @var list<ResultRecord>
	 */
	private array $records = [];

	/**
	 * Deduplication map: encoded primary key => ResultRecord.
	 *
	 * @var array<string, ResultRecord>
	 */
	private array $recordsById = [];

	/**
	 * Object IDs of records already registered in {@see $records}.
	 *
	 * @var array<int, true>
	 */
	private array $storedRecordIds = [];

	/**
	 * Lazily built parent lookup indexes: encoded field-list key => [encodedTuple => list<ResultRecord>].
	 *
	 * @var array<string, array<string, list<ResultRecord>>>
	 */
	private array $recordIndexes = [];

	/**
	 * Record count at the time each index in {@see $recordIndexes} was populated.
	 *
	 * @var array<string, int>
	 */
	private array $recordIndexCounts = [];

	/**
	 * O(1) dedup set for to-many child attachments: [parentObjId][relationName][childObjId] => true.
	 *
	 * @var array<int, array<string, array<int, true>>>
	 */
	private array $attachedChildIds = [];

	/**
	 * Bind this branch's schema node for the current fetch.
	 */
	public function setRepresentationSchema(RepresentationSchema $schema): void
	{
		$this->representationSchema = $schema;
	}

	public function getRepresentationSchema(): RepresentationSchema
	{
		return $this->representationSchema ?? throw LoadRuntimeException::schemaMissing();
	}

	public function setFetchMapping(FetchMapping $fetchMapping): void
	{
		$this->fetchMapping = $fetchMapping;
	}

	public function getFetchMapping(): FetchMapping
	{
		return $this->fetchMapping ?? throw new LogicException('Load branch fetch mapping is not configured.');
	}

	/**
	 * Branch column map: valueKey => SQL alias (`c0`, `c1`, ...).
	 *
	 * @return array<string, string>
	 */
	public function getColumns(): array
	{
		return $this->getFetchMapping()->columnsFor($this);
	}

	/**
	 * Extract this branch's planned column values from a raw SQL row.
	 *
	 * @param array<string, mixed> $sqlRow
	 * @return array<string, mixed>
	 */
	public function extractRow(array $sqlRow, bool $includeMissingAsNull = true): array
	{
		return $this->getFetchMapping()->extractRow($this, $sqlRow, $includeMissingAsNull);
	}

	abstract public function getCollection(): CollectionInterface;

	/**
	 * Projection level this branch writes onto: root {@see SelectQuery} or nested {@see RelationRef}.
	 */
	abstract public function getProjectionLevel(): SelectQuery|RelationRef;

	/**
	 * Record own-level fields required for identity or join correlation.
	 * {@see FetchMapping::planRequiredFields()} allocates SQL columns after schema planning.
	 *
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		if ($fieldNames === []) {
			return [];
		}

		$added = [];

		foreach ($fieldNames as $fieldName) {
			$normalized = $this->getCollection()->getField($fieldName)->getName();
			$this->requiredFieldNames[$normalized] = true;
			$added[] = $normalized;
		}

		return $added;
	}

	/**
	 * @return list<string>
	 */
	public function getRequiredFieldNames(): array
	{
		return array_keys($this->requiredFieldNames);
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		/** @var non-empty-list<string> */
		return $this->requireFields($this->getCollection()->getPrimaryKey());
	}

	/**
	 * Store the query/source chosen during the initial load-planning stage.
	 */
	public function setQueryContext(
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation,
	): void {
		$this->query = $query;
		$this->source = $source;
		$this->queryLocalRelation = $queryLocalRelation;
	}

	public function hasQuery(): bool
	{
		return $this->query !== null;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query ?? throw new LogicException('Load branch query context is not configured.');
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source ?? throw new LogicException('Load branch source context is not configured.');
	}

	public function getQueryLocalRelation(): ?RelationRef
	{
		return $this->queryLocalRelation;
	}

	/**
	 * @return list<RelationLoadBranch>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	/**
	 * Store a record on this branch, optionally deduplicating by $identityKey.
	 * Initializes child relation slots on newly stored records.
	 */
	public function storeRecord(ResultRecord $record, ?string $identityKey = null): ResultRecord
	{
		$objectId = spl_object_id($record);

		if (isset($this->storedRecordIds[$objectId])) {
			if ($identityKey !== null && ! isset($this->recordsById[$identityKey])) {
				$this->recordsById[$identityKey] = $record;
			}

			return $record;
		}

		if ($identityKey !== null && isset($this->recordsById[$identityKey])) {
			return $this->recordsById[$identityKey];
		}

		foreach ($this->children as $child) {
			$name = $child->getRelationRef()->getName();

			if (! array_key_exists($name, $record->children)) {
				$record->children[$name] = $child->returnsMany() ? [] : null;
			}
		}

		if ($identityKey !== null) {
			$this->recordsById[$identityKey] = $record;
		}

		$this->storedRecordIds[$objectId] = true;
		$this->records[] = $record;

		return $record;
	}

	/**
	 * Ingest one extracted value bag into this branch, deduplicating by collection
	 * primary key. Returns null when any primary-key field is null (e.g. LEFT JOIN miss).
	 *
	 * @param array<string, mixed> $values
	 */
	public function ingestRecord(array $values): ?ResultRecord
	{
		$primaryKey = $this->getCollection()->getPrimaryKey();
		$pkTuple = KeyEncoder::extractTuple($primaryKey, $values);

		if ($pkTuple === null) {
			return null;
		}

		return $this->storeRecord(new ResultRecord($values), KeyEncoder::encodeTuple($pkTuple));
	}

	/**
	 * @return list<ResultRecord>
	 */
	public function getRecords(): array
	{
		return $this->records;
	}

	/**
	 * Distinct non-null key tuples across all records currently loaded on this branch.
	 *
	 * @param list<string> $fields
	 * @return list<array<string, scalar>>
	 */
	public function getDistinctKeyValues(array $fields): array
	{
		if ($fields === []) {
			return [];
		}

		$distinct = [];

		foreach ($this->records as $record) {
			$tuple = KeyEncoder::extractTuple($fields, $record->values);

			if ($tuple === null) {
				continue;
			}

			$named = [];

			foreach ($fields as $index => $field) {
				$named[$field] = $tuple[$index];
			}

			$distinct[KeyEncoder::encodeTuple($tuple)] = $named;
		}

		return array_values($distinct);
	}

	/**
	 * Look up loaded records matching a scalar tuple for $fields.
	 *
	 * @param list<string> $fields
	 * @param list<scalar> $values
	 * @return list<ResultRecord>
	 */
	public function findRecordsByKey(array $fields, array $values): array
	{
		$indexKey = implode("\0", $fields);
		$indexedCount = $this->recordIndexCounts[$indexKey] ?? 0;
		$total = count($this->records);

		if ($indexedCount < $total) {
			for ($i = $indexedCount; $i < $total; ++$i) {
				$record = $this->records[$i];
				$tuple = KeyEncoder::extractTuple($fields, $record->values);

				if ($tuple === null) {
					continue;
				}

				$this->recordIndexes[$indexKey][KeyEncoder::encodeTuple($tuple)][] = $record;
			}

			$this->recordIndexCounts[$indexKey] = $total;
		}

		return $this->recordIndexes[$indexKey][KeyEncoder::encodeTuple($values)] ?? [];
	}

	public function attachChild(ResultRecord $parentRecord, RelationLoadBranch $child, ResultRecord $childRecord): void
	{
		$name = $child->getRelationRef()->getName();

		if (! $child->returnsMany()) {
			if (($parentRecord->children[$name] ?? null) !== null) {
				return;
			}

			$parentRecord->children[$name] = $child->ensureRecordStored($childRecord);

			return;
		}

		$childRecord = $child->ensureRecordStored($childRecord);
		$parentId = spl_object_id($parentRecord);
		$childId = spl_object_id($childRecord);

		if (isset($this->attachedChildIds[$parentId][$name][$childId])) {
			return;
		}

		$this->attachedChildIds[$parentId][$name][$childId] = true;
		$existing = is_array($parentRecord->children[$name] ?? null) ? $parentRecord->children[$name] : [];
		$existing[] = $childRecord;
		$parentRecord->children[$name] = $existing;
	}

	private function ensureRecordStored(ResultRecord $record): ResultRecord
	{
		if (isset($this->storedRecordIds[spl_object_id($record)])) {
			return $record;
		}

		return $this->storeRecord($record, $this->optionalPrimaryKeyIdentity($record->values));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function optionalPrimaryKeyIdentity(array $values): ?string
	{
		$tuple = [];

		foreach ($this->getCollection()->getPrimaryKey() as $field) {
			if (! array_key_exists($field, $values)) {
				return null;
			}

			$value = $values[$field];

			if (! is_scalar($value)) {
				return null;
			}

			$tuple[] = $value;
		}

		return $tuple === [] ? null : KeyEncoder::encodeTuple($tuple);
	}

	protected function addChild(RelationLoadBranch $child): void
	{
		$this->children[] = $child;
	}
}
