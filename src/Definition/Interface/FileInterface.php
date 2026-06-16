<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class FileInterface extends AbstractInterface
{
	protected ?string $rootFolder = null;

	public function rootFolder(string $rootFolder): self
	{
		$this->rootFolder = $rootFolder;

		return $this;
	}

	public function getRootFolder(): ?string
	{
		return $this->rootFolder;
	}
}
