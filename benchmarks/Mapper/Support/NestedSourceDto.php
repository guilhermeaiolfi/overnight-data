<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

final class NestedSourceDto
{
	public int $id;
	public int $tenantId;
	public string $title;
	public string $slug;
	public string $status;
	public bool $active;
	public bool $featured;
	public float $price;
	public float $rating;
	public int $priority;
	public int $revision;
	public string $language;
	public string $timezone;
	public string $owner;
	public string $editor;
	public ?string $summary = null;
	public ?string $excerpt = null;
	public string $createdAt;
	public string $updatedAt;
	public string $publishedAt;
	public NestedChildSourceDto $child;

	/** @var list<NestedChildSourceDto> */
	public array $children = [];
}
