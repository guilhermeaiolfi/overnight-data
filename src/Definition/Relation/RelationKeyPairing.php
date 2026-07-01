<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;

final class RelationKeyPairing
{
	private ?self $reversed = null;

	/**
	 * @var list<array{left: string, right: string}>
	 */
	private array $pairs;

	/**
	 * @param list<string> $leftFields
	 * @param list<string> $rightFields
	 */
	private function __construct(
		private readonly array $leftFields,
		private readonly array $rightFields,
	) {
		$this->pairs = array_map(
			static fn (string $left, string $right): array => ['left' => $left, 'right' => $right],
			$this->leftFields,
			$this->rightFields,
		);
	}

	/**
	 * @param list<string> $leftFields
	 * @param list<string> $rightFields
	 */
	public static function from(array $leftFields, array $rightFields): self
	{
		$leftFields = array_values($leftFields);
		$rightFields = array_values($rightFields);

		if ($leftFields === []) {
			throw new InvalidArgumentException('Relation key pairing requires at least one left field.');
		}

		if ($rightFields === []) {
			throw new InvalidArgumentException('Relation key pairing requires at least one right field.');
		}

		if (count($leftFields) !== count($rightFields)) {
			throw new InvalidArgumentException(sprintf(
				'Relation key pairing count mismatch: left has %d fields and right has %d.',
				count($leftFields),
				count($rightFields),
			));
		}

		return new self($leftFields, $rightFields);
	}

	/**
	 * @return list<string>
	 */
	public function getLeftFields(): array
	{
		return $this->leftFields;
	}

	/**
	 * @return list<string>
	 */
	public function getRightFields(): array
	{
		return $this->rightFields;
	}

	/**
	 * @return list<array{left: string, right: string}>
	 */
	public function getPairs(): array
	{
		return $this->pairs;
	}

	public function count(): int
	{
		return count($this->pairs);
	}

	public function isComposite(): bool
	{
		return $this->count() > 1;
	}

	public function reverse(): self
	{
		if ($this->reversed !== null) {
			return $this->reversed;
		}

		$reversed = new self($this->rightFields, $this->leftFields);
		$reversed->reversed = $this;

		return $this->reversed = $reversed;
	}
}
