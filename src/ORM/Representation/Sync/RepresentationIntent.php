<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

/**
 * Pending update/create intent for one representation until sync().
 */
final class RepresentationIntent
{
	/** @var list<FlatIntentOp> */
	private array $flatOps = [];

	/** @var Key|array<string, mixed>|null */
	private Key|array|null $identity = null;

	public function __construct(
		private RepresentationIntentLifecycle $lifecycle,
		private ?RepresentationSchema $schema = null,
		private ?CollectionInterface $rootCollection = null,
	) {
	}

	public function getLifecycle(): RepresentationIntentLifecycle
	{
		return $this->lifecycle;
	}

	public function setLifecycle(RepresentationIntentLifecycle $lifecycle): void
	{
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
		$this->schema = $schema;
	}

	public function getRootCollection(): ?CollectionInterface
	{
		return $this->rootCollection;
	}

	public function setRootCollection(?CollectionInterface $rootCollection): void
	{
		$this->rootCollection = $rootCollection;
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
		$this->flatOps[] = $op;
	}
}
