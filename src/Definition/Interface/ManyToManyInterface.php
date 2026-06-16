<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ManyToManyInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'type' => 'list',
			'template' => null,
			'columns' => null,
			'allow_creation' => false,
			'allow_selection' => false,
			'allow_duplication' => false,
			'allow_search' => false,
			'show_link' => false,
			'items_per_page' => 15,
		]);
	}

	public function allowDuplication(bool $allow_duplication): self
	{
		$this->set('allow_duplication', $allow_duplication);

		return $this;
	}

	public function isAllowDuplication(): bool
	{
		return (bool) $this->get('allow_duplication');
	}

	public function showLink(bool $show_link): self
	{
		$this->set('show_link', $show_link);

		return $this;
	}

	public function shouldShowLink(): bool
	{
		return (bool) $this->get('show_link');
	}

	public function allowCreation(bool $allow_creation): self
	{
		$this->set('allow_creation', $allow_creation);

		return $this;
	}

	public function isAllowCreation(): bool
	{
		return (bool) $this->get('allow_creation');
	}

	public function allowSelection(bool $allow_selection): self
	{
		$this->set('allow_selection', $allow_selection);

		return $this;
	}

	public function isAllowSelection(): bool
	{
		return (bool) $this->get('allow_selection');
	}

	public function allowSearch(bool $allow_search): self
	{
		$this->set('allow_search', $allow_search);

		return $this;
	}

	public function isAllowSearch(): bool
	{
		return (bool) $this->get('allow_search');
	}

	public function itemsPerPage(int $items_per_page): self
	{
		$this->set('items_per_page', $items_per_page);

		return $this;
	}

	public function getItemsPerPage(): int
	{
		return (int) $this->get('items_per_page');
	}

	public function type(string $type): self
	{
		$this->set('type', $type);

		return $this;
	}

	public function getType(): string
	{
		return (string) $this->get('type');
	}

	public function template(string $template): self
	{
		$this->set('template', $template);

		return $this;
	}

	public function getTemplate(): ?string
	{
		return is_string($this->get('template')) ? $this->get('template') : null;
	}

	public function columns(array $columns): self
	{
		$this->set('columns', $columns);

		return $this;
	}

	public function getColumns(): ?array
	{
		$value = $this->get('columns');

		return is_array($value) ? $value : null;
	}
}
