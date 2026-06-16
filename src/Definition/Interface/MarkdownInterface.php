<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class MarkdownInterface extends AbstractInterface
{
	protected array $toolbar = [];

	// editor || preview
	protected string $view = "editor";

	protected string $folder = "root";

	protected int $limit = 255;

	/**
	 * [
	 * 		type: 'block' or 'inline'
	 * 		name: 'something',
	 * 		icon: 'foo',
	 * 		prefix: ''
	 * 		sufix: ''
	 * ]
	**/
	protected array $custom_syntax = [];

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

	public function view(int $view): self
	{
		$this->view = $view;

		return $this;
	}

	public function getView(): string
	{
		return $this->view;
	}
}
