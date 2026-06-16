<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ManyToManyInterface extends AbstractInterface
{
	// list || table
	protected string $type = "list";

	// in case of $type == list
	protected ?string $template = null;

	// in case of $type == table
	protected ?array $columns = null;

	protected bool $allow_creation = false;

	protected bool $allow_selection = false;

	protected bool $allow_duplication = false;

	protected bool $allow_search = false;

	protected bool $show_link = false;

	protected int $items_per_page = 15;

	public function allowDuplication(bool $allow_duplication): self
	{
		$this->allow_duplication = $allow_duplication;

		return $this;
	}

	public function isAllowDuplication(): bool
	{
		return $this->allow_duplication;
	}

	public function showLink(bool $show_link): self
	{
		$this->show_link = $show_link;

		return $this;
	}

	public function shouldShowLink(): bool
	{
		return $this->show_link;
	}

	public function allowCreation(bool $allow_creation): self
	{
		$this->allow_creation = $allow_creation;

		return $this;
	}

	public function isAllowCreation(): bool
	{
		return $this->allow_creation;
	}

	public function allowSelection(bool $allow_selection): self
	{
		$this->allow_selection = $allow_selection;

		return $this;
	}

	public function isAllowSelection(): bool
	{
		return $this->allow_selection;
	}

	public function allowSearch(bool $allow_search): self
	{
		$this->allow_search = $allow_search;

		return $this;
	}

	public function isAllowSearch(): bool
	{
		return $this->allow_search;
	}

	public function itemsPerPage(int $items_per_page): self
	{
		$this->items_per_page = $items_per_page;

		return $this;
	}

	public function getItemsPerPage(): int
	{
		return $this->items_per_page;
	}

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function template(string $template): self
	{
		$this->template = $template;

		return $this;
	}

	public function getTemplate(): ?string
	{
		return $this->template;
	}

	public function columns(array $columns): self
	{
		$this->columns = $columns;

		return $this;
	}

	public function getColumns(): ?array
	{
		return $this->columns;
	}
}
