<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

enum FlushChangeSource: string
{
	case Record = 'record';
	case Extra = 'extra';
}
