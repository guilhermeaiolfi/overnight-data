<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingNode;

final class MappingWriter
{
	private readonly MappingNode $mappingNode;

	private mixed $result;

	public function __construct(
		MappingNode $mappingNode,
		private readonly WriterInterface $writer,
	) {
		$this->result = $this->writer->createTarget(
			node: $mappingNode,
		);

		$this->mappingNode = $mappingNode->withTarget($this->result);
	}

	public function createChildNode(
		string|int $name,
		mixed $value,
	): MappingNode {
		return $this->mappingNode->createChildNode(
			name: $name,
			value: $value,
		);
	}

	public function write(
		MappingNode $node,
		string|int $name,
		mixed $value,
	): void {
		$this->result = $this->writer->write(
			target: $this->result,
			name: $name,
			value: $value,
			node: $node,
		);
	}

	public function getResult(): mixed
	{
		return $this->result;
	}
}
