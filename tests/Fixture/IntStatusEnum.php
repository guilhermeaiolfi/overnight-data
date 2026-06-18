<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

enum IntStatusEnum: int
{
	case Draft = 0;
	case Published = 1;
}
