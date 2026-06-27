<?php

namespace Ngos\AdminCore\Dashboard\Widgets;

use Ngos\AdminCore\Dashboard\DashboardContext;
use Ngos\AdminCore\Dashboard\Widget;

/**
 * A compact list of recent / top rows. Implement rows() to return an iterable of
 * ['label' => string, 'meta' => ?string, 'link' => ?string, 'badge' => ?string] for the active range.
 */
abstract class ListWidget extends Widget
{
    public function type(): string
    {
        return 'list';
    }

    public function colSpan(): int
    {
        return 6;
    }

    /** @return iterable<int,array<string,mixed>> */
    abstract public function rows(DashboardContext $context): iterable;

    public function emptyText(): string
    {
        return 'Nothing yet.';
    }

    final public function data(DashboardContext $context): array
    {
        return [
            'title' => $this->title(),
            'rows' => $this->rows($context),
            'empty' => $this->emptyText(),
        ];
    }
}
