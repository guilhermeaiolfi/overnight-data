<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

use ON\Data\ORM\Binding\SelectQueryBindingCompiler;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\Query\SelectQuery;

final class MutableQueryResultTracker
{
	public function __construct(
		private ?SelectQueryBindingCompiler $compiler = null,
	) {
		$this->compiler ??= new SelectQueryBindingCompiler();
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>>|null $sourceRows
	 */
	public function trackAll(
		SelectQuery $query,
		Session $session,
		array $objects,
		?array $sourceRows = null,
	): RepresentationBinding {
		$binding = $this->compiler->compile($query);

		foreach ($objects as $index => $object) {
			$sourceRow = $sourceRows[$index] ?? null;
			$this->adopt($query, $session, $object, $binding, $sourceRow);
		}

		return $binding;
	}

	public function trackOne(
		SelectQuery $query,
		Session $session,
		object $object,
		?array $sourceRow = null,
	): RepresentationBinding {
		$binding = $this->compiler->compile($query);
		$this->adopt($query, $session, $object, $binding, $sourceRow);

		return $binding;
	}

	private function adopt(
		SelectQuery $query,
		Session $session,
		object $object,
		RepresentationBinding $binding,
		?array $sourceRow,
	): void {
		$session->adoptTrackedRepresentation(
			$object,
			$binding,
			$query->getCollection(),
			$sourceRow,
		);
	}
}
