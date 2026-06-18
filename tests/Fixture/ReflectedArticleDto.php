<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use DateTimeImmutable;

final class ReflectedArticleDto
{
	public StatusEnum $status;

	public IntStatusEnum $priority;

	public ?StatusEnum $optionalStatus = null;

	public DateTimeImmutable $publishedAt;

	public ?DateTimeImmutable $optionalPublishedAt = null;
}
