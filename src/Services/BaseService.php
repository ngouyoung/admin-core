<?php

namespace Ngos\AdminCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Base service every resource service extends — the shared business core used by
 * both the web (WebController) and JSON API (ApiController) channels.
 *
 * Receives plain arrays (never the HTTP Request) so it can be driven from
 * controllers, jobs, commands or tests, and signals "not found" with a native
 * ModelNotFoundException (→ 404) rather than a magic string.
 *
 * query() is the single read chokepoint — find, update, delete, trash, restore,
 * force-delete and reorder all flow through it, so a single query() override in a
 * host base service (e.g. a tenant_id scope) covers every read AND write across the
 * admin and the API — you can't reach a row outside your scope by any path.
 */
abstract class BaseService
{
    protected Model $model;

    /**
     * The parent/scope column that PARTITIONS `sort`, set by the generator from
     * `admin-core:make --sortable=<column>` (e.g. 'course_id'). null (the default) = global ordering.
     * Business-agnostic: the framework only knows a column name declared by trusted generated code —
     * NEVER a value from HTTP input. When set, reorder() is confined to a single scope value.
     */
    protected ?string $sortScope = null;

    public function query(array|string|null $relation = null): Builder
    {
        return $relation ? $this->model->with($relation) : $this->model->query();
    }

    public function find(int|string $id): Model
    {
        // Resolve by the model's route key (its public 'uuid' under the hybrid key
        // strategy, or 'id' for a plain bigint model) — never the raw primary key.
        // Routed through query() so a query() override (e.g. tenant scope) applies.
        return $this->query()->where($this->model->getRouteKeyName(), $id)->firstOrFail();
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int|string $id, array $data): Model
    {
        $model = $this->find($id);
        $model->update($data);

        return $model;
    }

    public function delete(int|string $id): void
    {
        $this->find($id)->delete();
    }

    // -- Soft deletes (only meaningful when the model uses SoftDeletes) --

    public function trashedQuery(array|string|null $relation = null): Builder
    {
        // Route through query() so a query() override (e.g. a tenant scope) also covers the trash view.
        return $this->query($relation)->onlyTrashed();
    }

    public function restore(int|string $id): void
    {
        $this->findTrashed($id)->restore();
    }

    public function forceDelete(int|string $id): void
    {
        $this->findTrashed($id)->forceDelete();
    }

    /** Look up a soft-deleted record by route key, honouring any query() scope (so you can't
        restore/force-delete a record outside your scope — e.g. another tenant's). */
    protected function findTrashed(int|string $id): Model
    {
        return $this->query()->onlyTrashed()->where($this->model->getRouteKeyName(), $id)->firstOrFail();
    }

    /**
     * Reconcile a hasMany relation against a posted set of rows — the master-detail / repeater pattern
     * (a Purchase + its line items, a Product + its units). Rows carrying an `id` are updated, new rows are
     * created, and rows no longer present are deleted. Pass $rows === null (the form didn't own the block,
     * e.g. an API/import call that omitted it) to leave the children untouched.
     *
     * Without $attributes each row is persisted as-is (minus its `id`) — so mass-assignment is bounded by
     * the child model's `$fillable` (give every line-item model an explicit `$fillable`, never `$guarded = []`).
     * Pass $attributes to whitelist or derive the columns explicitly — return null from it to skip a row:
     *
     *   $this->syncHasMany($purchase, 'items', $rows, fn ($r) => empty($r['product_id']) ? null : [
     *       'product_id' => $r['product_id'],
     *       'qty'        => $r['qty'] ?? 0,
     *   ]);
     *
     * Each row's `id` (when present) must be the child's primary-key value (what the repeater posts), which
     * `whereKey()` resolves; new rows omit it.
     *
     * @return array<int, mixed> the kept child keys (created + updated)
     */
    protected function syncHasMany(Model $parent, string $relation, ?array $rows, ?callable $attributes = null): array
    {
        if ($rows === null) {
            return [];
        }

        $keep = [];
        foreach ($rows as $row) {
            $attrs = $attributes ? $attributes($row) : collect($row)->except('id')->all();
            if ($attrs === null) {
                continue; // the transform opted to skip this row (e.g. blank)
            }
            $id = $row['id'] ?? null;
            $existing = $id ? $parent->{$relation}()->whereKey($id)->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $keep[] = $existing->getKey();
            } else {
                $keep[] = $parent->{$relation}()->create($attrs)->getKey();
            }
        }

        // Anything not in the submission is removed (an empty $rows ⇒ all children cleared).
        $parent->{$relation}()->when($keep !== [], fn ($q) => $q->whereKeyNot($keep))->delete();

        return $keep;
    }

    /**
     * Persist a new order — each (route-key) id's `sort` becomes its 1-based position.
     *
     * GLOBAL resource (no `$sortScope`): `reorder($ids)` renumbers rows as one sequence and an id you cannot
     * see through query() simply updates nothing (a no-op). A `$scopeId` passed to a global service is inert.
     * This lenient behaviour is unchanged.
     *
     * SCOPED resource (declares `$sortScope`, from `--sortable=<column>`): the scope VALUE is MANDATORY at
     * runtime — the service enforces its own declared invariant. `reorder($ids, null)` (or omitting the arg)
     * throws \InvalidArgumentException BEFORE any write; it never silently falls back to a global cross-scope
     * renumber (a forgotten scope value is a programming error, not a global request). With a `$scopeId`,
     * ordering is PARTITIONED by `$sortScope` under a STRICT integrity contract (ADR-0007): the submitted list
     * must be a DUPLICATE-FREE set of EXISTING rows ALL visible through the effective scoped query. If any id
     * is duplicated, nonexistent, soft-deleted, hidden by a query() override, or in another scope, the WHOLE
     * operation is rejected (ValidationException) before any write. The scope COLUMN is always the resource's
     * own generated `$sortScope`, never a caller/HTTP parameter.
     */
    public function reorder(array $ids, mixed $scopeId = null): void
    {
        $key = $this->model->getRouteKeyName();

        // A resource that DECLARES a scope column enforces its own architectural invariant: a scope VALUE is
        // required. Refuse (fail fast, before any write) rather than silently run a global cross-scope
        // renumber — a trusted internal caller must not turn a scoped service global by forgetting the value.
        if ($this->sortScope !== null && $scopeId === null) {
            throw new \InvalidArgumentException(sprintf(
                'A scope id is required to reorder [%s]: this resource orders within "%s". Pass the parent key, e.g. reorder($ids, $parent->getKey()).',
                $this->model::class,
                $this->sortScope,
            ));
        }

        $scoped = $this->sortScope !== null; // $scopeId is guaranteed non-null here (guarded above)
        // One transaction so a mid-loop failure can't leave a half-renumbered (corrupt) order.
        DB::transaction(function () use ($ids, $key, $scoped, $scopeId) {
            if ($scoped) {
                // Reject unless the submitted set is duplicate-free AND every id resolves to a distinct row
                // inside the effective scoped query. Comparing three counts covers every failure at once:
                //   count(submitted)  !== count(distinct)  → a duplicate id was submitted
                //   count(distinct)   !== count(in scope)  → some id is nonexistent / soft-deleted /
                //                                            query()-hidden / in another scope
                $submitted = array_map('strval', $ids);
                $distinct = count(array_unique($submitted));
                $inScope = $this->query()
                    ->where($this->sortScope, $scopeId)
                    ->whereIn($key, $ids)
                    ->count();
                if (count($submitted) !== $distinct || $distinct !== $inScope) {
                    throw ValidationException::withMessages([
                        'ids' => 'The items to reorder must be a unique set of rows that all belong to this list.',
                    ]);
                }
            }
            foreach (array_values($ids) as $position => $id) {
                // Scoped through query() (so a query() override still applies) AND, when scoped, confined
                // to $sortScope = $scopeId — a reorder can never write `sort` onto a row outside the scope.
                $q = $this->query()->where($key, $id);
                if ($scoped) {
                    $q->where($this->sortScope, $scopeId);
                }
                $q->update(['sort' => $position + 1]);
            }
        });
    }
}
