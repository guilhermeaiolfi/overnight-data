<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\SelectQuery;

abstract class LoadBranch
{
	private ?AbstractNode $node = null;

	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	private ?AbstractNode $publicNode = null;

	private ?string $publicPayloadChild = null;

	/**
	 * @var list<RelationLoadBranch>
	 */
	private array $children = [];

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Load branch parser node is not registered.');
	}

	public function hasNode(): bool
	{
		return $this->node !== null;
	}

	public function setPublicNode(AbstractNode $node): void
	{
		$this->publicNode = $node;
	}

	public function getPublicNode(): AbstractNode
	{
		return $this->publicNode ?? $this->getNode();
	}

	public function setPublicPayloadChild(?string $container): void
	{
		$this->publicPayloadChild = $container;
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

	protected function addChild(RelationLoadBranch $child): void
	{
		$this->children[] = $child;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|null
	 */
	protected function payloadRecord(array $record): ?array
	{
		$container = $this->publicPayloadChild;

		if ($container === null) {
			return $record;
		}

		$payload = $record[$container] ?? null;

		return is_array($payload) ? $payload : null;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $promotions
	 */
	protected function mergePromotions(array &$item, array $promotions, string $parentPath): void
	{
		foreach ($promotions as $name => $entry) {
			if (array_key_exists($name, $item)) {
				throw RelationSelectionException::ambiguousPromotion($parentPath, $name);
			}

			$item[$name] = $entry['value'];
		}
	}

	/**
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
	 */
	protected function mergeHiddenNameMaps(array &$target, array $incoming, string $parentPath): void
	{
		foreach ($incoming as $name => $entry) {
			if (isset($target[$name]) && $target[$name]['branch'] !== $entry['branch']) {
				throw RelationSelectionException::ambiguousPromotion($parentPath, $name);
			}

			$target[$name] = $entry;
		}
	}

	/**
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
	 */
	protected function mergeHiddenCollectionPromotions(array &$target, array $incoming): void
	{
		foreach ($incoming as $name => $entry) {
			$branch = $entry['branch'];

			if (! isset($target[$name])) {
				$target[$name] = [
					'branch' => $branch,
					'collection' => true,
					'value' => [],
					'items' => [],
				];
			} elseif ($target[$name]['branch'] !== $branch) {
				throw RelationSelectionException::ambiguousPromotion(implode('.', $branch->getRelationRef()->getPath()), $name);
			}

			foreach ($entry['items'] as $item) {
				if (! $this->containsPromotionItem($target[$name]['items'], $item['identity'])) {
					$target[$name]['items'][] = $item;
					$target[$name]['value'][] = $item['value'];
				}
			}
		}
	}

	/**
	 * @param list<array{identity: string, value: mixed}> $existing
	 */
	protected function containsPromotionItem(array $existing, string $candidateIdentity): bool
	{
		foreach ($existing as $item) {
			if ($item['identity'] === $candidateIdentity) {
				return true;
			}
		}

		return false;
	}
}
