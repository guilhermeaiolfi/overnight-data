<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class MarkdownInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'toolbar' => [],
			'view' => 'editor',
			'folder' => 'root',
			'limit' => 255,
			'custom_syntax' => [],
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

	public function view(int $view): self
	{
		$this->set('view', $view);

		return $this;
	}

	public function getView(): string
	{
		return (string) $this->get('view');
	}
}
