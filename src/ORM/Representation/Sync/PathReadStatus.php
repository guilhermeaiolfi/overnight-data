<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

enum PathReadStatus
{
	case Present;
	case Missing;
	case Invalid;
}
