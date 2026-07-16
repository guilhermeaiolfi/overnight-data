<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

enum RepresentationIntentLifecycle
{
	case Update;
	case Create;
}
