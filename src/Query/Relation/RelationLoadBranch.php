<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

final class RelationLoadBranch extends LoadBranch
{
	private readonly SelectionList $selections;

	private ?string $continuationMethod = null;

	private ?SelectQuery $continuationQuery = null;

	private ?bool $joinedAttachment = null;

	public function __construct(
		private readonly RelationSelection $selection,
		private readonly LoadBranch $parent,
		private readonly LoaderInterface $loader,
	) {
		$this->selections = new SelectionList();
		$this->parent->addChild($this);
		$this->registerPublicSelections();
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection;
	}

	public function getRelationRef(): RelationRef
	{
		return $this->selection->getRelationRef();
	}

	public function getProjectionLevel(): RelationRef
	{
		return $this->getRelationRef();
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
			$this->selections->add($this->relationFieldSelection($canonical), SelectionTag::REQUIRED);
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
			$this->selections->add($this->relationFieldSelection($canonical), SelectionTag::PUBLIC);
			$added[] = $canonical;
		}

		$this->requireFields($fieldNames);

		return $added;
	}

	/**
	 * Register this level's public projection onto the load-branch selection list.
	 * Loaders still fetch tables; runtime owns how public keys are assembled.
	 */
	public function registerPublicSelections(): void
	{
		if (! $this->selection->isLoaded()) {
			return;
		}

		if ($this->selection->hasDefaultSelection()) {
			$this->addPublicFields($this->getRelationRef()->getCollection()->getVisibleFields());

			return;
		}

		foreach ($this->selection->getSelections()->getExplicit() as $selection) {
			$expression = $selection->getExpression();

			if ($expression instanceof StarExpression && $expression->getSource() === $this->getRelationRef()) {
				$this->addPublicFields($this->getRelationRef()->getCollection()->getVisibleFields());

				continue;
			}

			$ownField = $this->ownFieldSelection($selection);

			if ($ownField !== null) {
				[$fieldName, $publicAlias] = $ownField;
				$this->selections->add(
					$this->getRelationRef()->field($fieldName)->as($publicAlias),
					SelectionTag::PUBLIC,
					true,
				);
				$this->requireFields([$fieldName]);

				continue;
			}

			$flatField = $this->descendantFieldSelection($selection);

			if ($flatField === null) {
				continue;
			}

			// Fetch-only: place keys come from RepresentationSchema (proposal 0003 Phase 2).
			[$fieldRef, $publicAlias] = $flatField;
			$this->selections->add(
				$fieldRef->as($publicAlias),
				SelectionTag::COLUMN,
				false,
			);
		}

		$this->registerInternalSelections();
	}

	/**
	 * Fetch INTERNAL identity columns planned for nested flat writable adoption.
	 */
	private function registerInternalSelections(): void
	{
		foreach ($this->selection->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$ownField = $this->ownFieldSelection($selection);

			if ($ownField !== null) {
				[$fieldName, $publicAlias] = $ownField;
				$this->selections->add(
					$this->getRelationRef()->field($fieldName)->as($publicAlias),
					[SelectionTag::INTERNAL, SelectionTag::COLUMN],
				);
				$this->requireFields([$fieldName]);

				continue;
			}

			$flatField = $this->descendantFieldSelection($selection);

			if ($flatField === null) {
				continue;
			}

			[$fieldRef, $alias] = $flatField;
			$this->selections->add(
				$fieldRef->as($alias),
				[SelectionTag::INTERNAL, SelectionTag::COLUMN],
			);
		}
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
		return $this->joinedAttachment ?? throw new LogicException('Load branch attachment mode is not configured.');
	}

	public function returnsMany(): bool
	{
		return $this->getRelationRef()->getDefinition()->getCardinality()->isMany();
	}

	private function fieldSelectionName(string $fieldName): string
	{
		return $this->getRelationRef()->field($fieldName)->getField()->getName();
	}

	private function relationFieldSelection(string $fieldName): AliasedExpression
	{
		return $this->getRelationRef()->field($fieldName)->as($fieldName);
	}

	/**
	 * @return ?array{0: string, 1: string} field name + public alias
	 */
	private function ownFieldSelection(SelectionItem $selection): ?array
	{
		$expression = $selection->getExpression();
		$publicAlias = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$publicAlias = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef || $expression->getSource() !== $this->getRelationRef()) {
			return null;
		}

		return [$expression->getField()->getName(), $publicAlias];
	}

	/**
	 * Flat related FieldRef under this level (e.g. posts.author.name as authorName).
	 *
	 * @return ?array{0: FieldRef, 1: string}
	 */
	private function descendantFieldSelection(SelectionItem $selection): ?array
	{
		$expression = $selection->getExpression();
		$publicAlias = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$publicAlias = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return null;
		}

		$source = $expression->getSource();

		if (
			! $source instanceof RelationRef
			|| ! $source->isUnder($this->getRelationRef())
		) {
			return null;
		}

		return [$expression, $publicAlias];
	}
}
