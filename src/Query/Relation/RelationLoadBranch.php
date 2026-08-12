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
	public function addPublicFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			$canonical = $this->fieldSelectionName($fieldName);
			$this->selections->add($this->relationFieldSelection($canonical), SelectionTag::EXPLICIT);
			$added[] = $canonical;
		}

		$this->requireFields($fieldNames);

		return $added;
	}

	/**
	 * Register this level's public + INTERNAL projection onto the load-branch selection list.
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
			$this->registerLevelSelection($selection, [SelectionTag::EXPLICIT], flatsAsColumnOnly: true);
		}

		foreach ($this->selection->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$this->registerLevelSelection(
				$selection,
				[SelectionTag::INTERNAL, SelectionTag::COLUMN],
				flatsAsColumnOnly: false,
			);
		}
	}

	/**
	 * @param list<string> $tags
	 */
	private function registerLevelSelection(
		SelectionItem $selection,
		array $tags,
		bool $flatsAsColumnOnly,
	): void {
		$expression = $selection->getExpression();

		if ($expression instanceof StarExpression && $expression->getSource() === $this->getRelationRef()) {
			$this->addPublicFields($this->getRelationRef()->getCollection()->getVisibleFields());

			return;
		}

		[$fieldRef, $alias] = $this->unwrapFieldSelection($selection) ?? [null, null];

		if (! $fieldRef instanceof FieldRef) {
			return;
		}

		if ($fieldRef->getSource() === $this->getRelationRef()) {
			$this->selections->add(
				$this->getRelationRef()->field($fieldRef->getField()->getName())->as($alias),
				$tags,
			);
			$this->requireFields([$fieldRef->getField()->getName()]);

			return;
		}

		$source = $fieldRef->getSource();

		if (! $source instanceof RelationRef || ! $source->isUnder($this->getRelationRef())) {
			return;
		}

		// Fetch-only flats: place keys come from RepresentationSchema.
		// Keep EXPLICIT off flats so they are not treated as own-level place from tags.
		$this->selections->add(
			$fieldRef->as($alias),
			$flatsAsColumnOnly ? SelectionTag::COLUMN : $tags,
		);
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
	 * @return ?array{0: FieldRef, 1: string}
	 */
	private function unwrapFieldSelection(SelectionItem $selection): ?array
	{
		$expression = $selection->getExpression();
		$alias = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$alias = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		return $expression instanceof FieldRef ? [$expression, $alias] : null;
	}
}
