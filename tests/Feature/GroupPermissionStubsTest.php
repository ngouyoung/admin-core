<?php

// The GroupPermissions manager ships as App\-namespace stubs that only run in a host app. Pin the shipped
// content so the hybrid-key parent-exclusion fix (the uuid route key must not be compared to the bigint id
// column) and the self-parent guard don't regress. Mirrors TwoFactorStubsTest's stub-content assertion style.

function gpStub(string $rel): string
{
    return (string) file_get_contents(__DIR__ . '/../../stubs/' . $rel);
}

it('excludes self from the Parent dropdown by the real primary key, not the uuid route param', function () {
    // `->where('id', '!=', $id)` compared the bigint `id` column to the uuid route key: 500 on Postgres
    // (bigint vs uuid) and never matches on MySQL (so the group can pick itself → it orphans out of the tree).
    // It must exclude by the loaded object's real primary key.
    expect(gpStub('access/Http/Controllers/Backend/Assessments/GroupPermissionsController.php.stub'))
        ->toContain("where('id', '!=', \$object->getKey())")
        ->not->toContain("where('id', '!=', \$id)");
});

it('rejects a group choosing itself as its own parent, resolving the id from the uuid route key', function () {
    // Defense-in-depth: even if the dropdown is bypassed, a crafted parent_id == self must be rejected (a
    // self-parented group is unreachable from the tree manager). parent_id is the bigint id; the route key is
    // the uuid, so the request resolves the group's own id to exclude it.
    expect(gpStub('access/Http/Requests/GroupPermission/UpdateGroupPermissionRequest.php.stub'))
        ->toContain("where('uuid', \$id)->value('id')")
        ->toContain('Rule::notIn([$selfId])');
});
