<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class FileInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'rootFolder' => null,
		]);
	}

	public function rootFolder(string $rootFolder): self
	{
		$this->set('rootFolder', $rootFolder);

		return $this;
	}

	public function getRootFolder(): ?string
	{
		return is_string($this->get('rootFolder')) ? $this->get('rootFolder') : null;
	}
}
