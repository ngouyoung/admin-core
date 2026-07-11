<?php

namespace Ngos\AdminCore\Support\Permissions;

use Illuminate\Support\Str;

/**
 * The permission requirement of one resource on one guard — a value object discovered from the routes and
 * consumed by {@see PermissionSynchronizer}. Immutable; the resource is the kebab slug used in the
 * permission pattern (e.g. `course-review`), and the plural/label/group are derived for display + filing.
 */
final class ResourcePermissionSpec
{
    /** @param  list<string>  $actions  a subset of PermissionNaming::CRUD_ACTIONS */
    public function __construct(
        public readonly string $resource,
        public readonly string $guard,
        public readonly array $actions,
    ) {}

    /** Stable dedupe/sort key for a (guard, resource) pair. */
    public function key(): string
    {
        return $this->guard."\0".$this->resource;
    }

    /** The permission names this resource needs, in CRUD order. @return list<string> */
    public function permissionNames(): array
    {
        return array_map(fn (string $action) => PermissionNaming::name($action, $this->resource), $this->actions);
    }

    /** Human display label, e.g. `region` → `Regions`. */
    public function label(): string
    {
        return Str::headline(Str::plural($this->resource));
    }

    /** Group-permission bucket name, e.g. `Regions Management`. */
    public function group(): string
    {
        return $this->label().' Management';
    }

    /** A copy whose action set is the union with $actions, re-ordered to canonical CRUD order. */
    public function withActions(array $actions): self
    {
        $merged = array_values(array_filter(
            PermissionNaming::CRUD_ACTIONS,
            fn (string $a) => in_array($a, $this->actions, true) || in_array($a, $actions, true),
        ));

        return new self($this->resource, $this->guard, $merged);
    }
}
