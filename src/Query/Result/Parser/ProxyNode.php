<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
final class ProxyNode extends AbstractNode
{
	/**
	 * @var array<string, AbstractNode>
	 */
	private array $includeNodes = [];

	/**
	 * @param non-empty-list<string> $parentFields
	 */
	public function __construct(array $parentFields)
	{
		parent::__construct([], $parentFields);
		$this->validateFieldList($parentFields, 'Parent reference fields', false);
	}

	public function addNode(string $target, AbstractNode $node): AbstractNode
	{
		if ($target === '') {
			throw new ParserException('A proxy node target must be a non-empty string.');
		}

		if ($this->parent === null || $this->container === null) {
			throw new ParserException('A proxy node must be attached to a parent before role-specific child nodes can be added.');
		}

		if (! array_key_exists($target, $this->includeNodes)) {
			$this->includeNodes[$target] = $node;
			$this->parent->attachProxiedNode($this->container, $this, $node);
		}

		return $this->includeNodes[$target];
	}

	public function linkNode(?string $container, AbstractNode $node): void
	{
		$this->parent?->linkNode($container, $node);
	}

	public function joinNode(?string $container, AbstractNode $node): void
	{
		$this->parent?->joinNode($container, $node);
	}

	protected function push(array &$data): void
	{
	}
}
