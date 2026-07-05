<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RepresentationState
{
	/** @var array<string, int> */
	private array $baselineRevisions;

	/**
	 * @param array<string, int> $baselineRevisions
	 */
	public function __construct(
		private RepresentationBinding $binding,
		array $baselineRevisions,
	) {
		$this->baselineRevisions = $this->normalizeBaselineRevisions($baselineRevisions);
	}

	public function getBinding(): RepresentationBinding
	{
		return $this->binding;
	}

	/**
	 * @return array<string, int>
	 */
	public function getBaselineRevisions(): array
	{
		return $this->baselineRevisions;
	}

	public function hasBaselineRevision(string $recordHash): bool
	{
		return array_key_exists($recordHash, $this->baselineRevisions);
	}

	public function getBaselineRevision(string $recordHash): int
	{
		if (! array_key_exists($recordHash, $this->baselineRevisions)) {
			throw new StateException(sprintf("Representation state has no baseline revision for record '%s'.", $recordHash));
		}

		return $this->baselineRevisions[$recordHash];
	}

	public function getBaselineRevisionFor(RecordFieldRef $field): int
	{
		return $this->getBaselineRevision($field->getRecordHash());
	}

	/**
	 * @param array<string, int> $baselineRevisions
	 */
	public function replaceBaselineRevisions(array $baselineRevisions): void
	{
		$this->baselineRevisions = $this->normalizeBaselineRevisions($baselineRevisions);
	}

	/**
	 * @param array<string, int> $baselineRevisions
	 * @return array<string, int>
	 */
	private function normalizeBaselineRevisions(array $baselineRevisions): array
	{
		foreach ($baselineRevisions as $recordHash => $revision) {
			if (! is_string($recordHash) || $recordHash === '') {
				throw new StateException('Representation state baseline revision record hashes must be non-empty strings.');
			}

			if ($revision < 1) {
				throw new StateException(sprintf("Representation state baseline revision for record '%s' must be positive.", $recordHash));
			}
		}

		return $baselineRevisions;
	}
}
