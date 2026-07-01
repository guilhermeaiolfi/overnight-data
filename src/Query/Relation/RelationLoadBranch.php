<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionReason;
use ON\Data\Query\SelectQuery;

final class RelationLoadBranch extends LoadBranch
{
	private readonly SelectionList $selections;

	private ?string $continuationMethod = null;

	private ?SelectQuery $continuationQuery = null;

	private ?bool $joinedAttachment = null;

	/**
	 * @param list<string> $publicFields
	 */
	public function __construct(
		private readonly RelationSelection $selection,
		private readonly LoadBranch $parent,
		private readonly LoaderInterface $loader,
		array $publicFields,
	) {
		$this->selections = new SelectionList();
		$this->parent->addChild($this);
		$this->addPublicFields($publicFields);
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection;
	}

	public function getRelationRef(): RelationRef
	{
		return $this->selection->getRelationRef();
	}

	public function getParent(): LoadBranch
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->getRelationRef()->getCollection();
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			$canonical = $this->fieldSelectionName($fieldName);
			$this->selections->add($this->relationFieldSelection($canonical), SelectionReason::REQUIRED);
			$added[] = $canonical;
		}

		return $added;
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function addPublicFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			$canonical = $this->fieldSelectionName($fieldName);
			$this->selections->add($this->relationFieldSelection($canonical), SelectionReason::PUBLIC);
			$added[] = $canonical;
		}

		$this->requireFields($fieldNames);

		return $added;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getReferenceValues(): array
	{
		return $this->getNode()->getReferenceValues();
	}

	public function setContinuation(string $method, SelectQuery $query): void
	{
		$this->continuationMethod = $method;
		$this->continuationQuery = $query;
	}

	public function clearContinuation(): void
	{
		$this->continuationMethod = null;
		$this->continuationQuery = null;
	}

	public function getContinuationMethod(): ?string
	{
		return $this->continuationMethod;
	}

	public function getContinuationQuery(): ?SelectQuery
	{
		return $this->continuationQuery;
	}

	public function setJoinedAttachment(bool $joined): void
	{
		$this->joinedAttachment = $joined;
	}

	public function isJoinedAttachment(): bool
	{
		return $this->joinedAttachment ?? throw new \LogicException('Load branch attachment mode is not configured.');
	}

	public function returnsMany(): bool
	{
		return $this->getRelationRef()->getDefinition()->getCardinality() === 'many';
	}

	private function fieldSelectionName(string $fieldName): string
	{
		return $this->getRelationRef()->field($fieldName)->getField()->getName();
	}

	private function relationFieldSelection(string $fieldName): \ON\Data\Query\Expression\AliasedExpression
	{
		return $this->getRelationRef()->field($fieldName)->as($fieldName);
	}
}
