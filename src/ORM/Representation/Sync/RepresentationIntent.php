<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\Schema\RepresentationSchema;

/**
 * Pending update/create intent for one representation until sync() or flush().
 */
final class RepresentationIntent
{
	/** @var list<FlatIntentOp> */
	private array $flatOps = [];

	/** @var Key|array<string, mixed>|null */
	private Key|array|null $identity = null;

	/** Local binding can finish before a related object's intent fails. */
	private ?RepresentationState $boundState = null;

	public function __construct(
		private RepresentationIntentLifecycle $lifecycle,
		private ?RepresentationSchema $schema = null,
	) {
	}

	/** @internal */
	public function isBoundTo(RepresentationState $state): bool
	{
		return $this->boundState === $state;
	}

	/** @internal */
	public function setBoundState(?RepresentationState $state): void
	{
		$this->boundState = $state;
	}

	public function getLifecycle(): RepresentationIntentLifecycle
	{
		return $this->lifecycle;
	}

	public function setLifecycle(RepresentationIntentLifecycle $lifecycle): void
	{
		if ($this->lifecycle !== $lifecycle) {
			$this->boundState = null;
		}
		$this->lifecycle = $lifecycle;
	}

	public function isUpdate(): bool
	{
		return $this->lifecycle === RepresentationIntentLifecycle::Update;
	}

	public function isCreate(): bool
	{
		return $this->lifecycle === RepresentationIntentLifecycle::Create;
	}

	public function getSchema(): ?RepresentationSchema
	{
		return $this->schema;
	}

	public function setSchema(?RepresentationSchema $schema): void
	{
		if ($this->schema !== $schema) {
			$this->boundState = null;
		}
		$this->schema = $schema;
	}

	/**
	 * @return Key|array<string, mixed>|null
	 */
	public function getIdentity(): Key|array|null
	{
		return $this->identity;
	}

	/**
	 * @param Key|array<string, mixed>|null $identity
	 */
	public function setIdentity(Key|array|null $identity): void
	{
		if ($this->identity !== $identity) {
			$this->boundState = null;
		}
		$this->identity = $identity;
	}

	/**
	 * @return list<FlatIntentOp>
	 */
	public function getFlatOps(): array
	{
		return $this->flatOps;
	}

	public function addFlatOp(FlatIntentOp $op): void
	{
		$this->boundState = null;
		$this->flatOps[] = $op;
	}
}
