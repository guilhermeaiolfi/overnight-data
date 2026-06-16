<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class WYSIWYGInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'toolbar' => [],
			'folder' => 'root',
			'limit' => 255,
		]);
	}

	public function toolbar(array $toolbar): self
	{
		$this->set('toolbar', $toolbar);

		return $this;
	}

	public function getToolbar(): ?array
	{
		$value = $this->get('toolbar');

		return is_array($value) ? $value : null;
	}

	public function folder(array $folder): self
	{
		$this->set('folder', $folder);

		return $this;
	}

	public function getFolder(): string
	{
		return (string) $this->get('folder');
	}

	public function limit(int $limit): self
	{
		$this->set('limit', $limit);

		return $this;
	}

	public function getLimit(): int
	{
		return (int) $this->get('limit');
	}
}
