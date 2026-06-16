<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class WYSIWYGInterface extends AbstractInterface
{
	protected array $toolbar = [];

	protected string $folder = "root";

	protected int $limit = 255;

	public function toolbar(array $toolbar): self
	{
		$this->toolbar = $toolbar;

		return $this;
	}

	public function getToolbar(): ?array
	{
		return $this->toolbar;
	}

	public function folder(array $folder): self
	{
		$this->folder = $folder;

		return $this;
	}

	public function getFolder(): string
	{
		return $this->folder;
	}

	public function limit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}
}
