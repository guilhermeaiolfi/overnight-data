<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Selection\SelectionItem;

interface ProjectionIdentityProviderInterface
{
	public function fieldForSelection(SelectionItem $selection, FieldRef $fieldRef): ?RecordFieldRef;
}
