<?php

namespace App\Telegram\Support;

/**
 * Helper for InlineMenu subclasses to lay out a list of buttons in rows of N.
 */
trait GridButtons
{
    protected function addButtonGrid(array $buttons, int $perRow = 2): static
    {
        foreach (array_chunk($buttons, $perRow) as $row) {
            $this->addButtonRow(...$row);
        }

        return $this;
    }
}
