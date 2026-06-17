<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class TargetPostNestedDto
{
	public TargetAuthorDto $author;

	/** @var list<TargetAuthorDto> */
	public array $authors = [];

	public bool $active = true;
}
