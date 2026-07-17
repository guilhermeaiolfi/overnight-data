<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Key;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\FlatIntentOp;
use ON\Data\ORM\Representation\Sync\RepresentationIntent;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;

/**
 * Fluent writer into RepresentationIntentStore for one representation.
 *
 * Root collection comes from the {@see RepresentationSchema} passed to
 * Session::update/create (or from an already-tracked representation), not from
 * a separate from() call.
 */
final class IntentBuilder
{
	public function __construct(
		private object $representation,
		private RepresentationIntent $intent,
	) {
	}

	public function getRepresentation(): object
	{
		return $this->representation;
	}

	/**
	 * Root identity when the PK is not readable from the DTO/map.
	 *
	 * @param Key|array<string, mixed> $identity
	 */
	public function identity(Key|array $identity): self
	{
		$this->intent->setIdentity($identity);

		return $this;
	}

	/**
	 * Flat related-path update. Pass $key when the PK is not on the projection shape.
	 *
	 * @param Key|array<string, mixed>|null $key
	 */
	public function update(string $path, Key|array|null $key = null): self
	{
		$this->intent->addFlatOp(new FlatIntentOp($path, 'update', $key));

		return $this;
	}

	/**
	 * Flat related-path create (new related record + relation add intent on sync).
	 */
	public function create(string $path): self
	{
		$this->intent->addFlatOp(new FlatIntentOp($path, 'create'));

		return $this;
	}

	public function withSchema(RepresentationSchema $schema): self
	{
		$this->intent->setSchema($schema);

		return $this;
	}

	public function getIntent(): RepresentationIntent
	{
		return $this->intent;
	}

	public function getLifecycle(): RepresentationIntentLifecycle
	{
		return $this->intent->getLifecycle();
	}
}
