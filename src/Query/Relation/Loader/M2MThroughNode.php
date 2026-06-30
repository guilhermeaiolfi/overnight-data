<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\ParserException;

final class M2MThroughNode extends AbstractNode
{
	/**
	 * @param list<string> $columns
	 * @param list<string> $identityFields
	 * @param non-empty-list<string> $childFields
	 * @param non-empty-list<string> $parentFields
	 */
	public function __construct(
		array $columns,
		array $identityFields,
		private array $childFields,
		array $parentFields,
		private readonly string $publicChildContainer,
		private readonly AbstractNode $publicChildNode,
	) {
		parent::__construct($columns, $parentFields);
		$this->childFields = $this->validateFieldList($this->childFields, 'Child reference fields', false);
		$this->assertFieldsExist($this->childFields, $this->columns, 'Child reference field');
		$this->assertFieldsExist($identityFields, $this->columns, 'Identity field');

		if (count($this->childFields) !== count($this->parentFields)) {
			throw new ParserException('Parent and child reference field counts must match.');
		}

		$this->setIdentityFields($this->validateFieldList($identityFields, 'Identity fields'));
		parent::joinNode($this->publicChildContainer, $this->publicChildNode);
	}

	public function getRelationAttachmentNode(): AbstractNode
	{
		return $this->publicChildNode;
	}

	public function isCollectionLike(): bool
	{
		return true;
	}

	protected function push(array &$data): void
	{
		if ($this->parent === null) {
			throw new ParserException('Unable to register a collection child record because the parent node is missing.');
		}

		foreach ($this->childFields as $field) {
			if ($data[$field] === null) {
				return;
			}
		}

		$this->parent->mountArray(
			$this->container ?? throw new ParserException('Unable to mount a collection child because its container name is undefined.'),
			$this->getParentReferenceIndex(),
			$this->orderedFieldValues($this->childFields, $data),
			$data,
		);
	}
}
