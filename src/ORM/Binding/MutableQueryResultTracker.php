<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

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
	 */
	public function trackAll(SelectQuery $query, Session $session, array $objects): RepresentationBinding
	{
		$binding = $this->compiler->compile($query);

		foreach ($objects as $object) {
			$session->sync($object, $binding);
		}

		return $binding;
	}

	public function trackOne(SelectQuery $query, Session $session, object $object): RepresentationBinding
	{
		$binding = $this->compiler->compile($query);
		$session->sync($object, $binding);

		return $binding;
	}
}
