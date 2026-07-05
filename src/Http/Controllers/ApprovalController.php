<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Ngos\AdminCore\Models\Approval;
use Ngos\AdminCore\Notifications\AdminNotification;

/**
 * The approvals inbox: list pending requests and approve / reject them. Reaching the inbox needs the
 * `list-approval` permission (route middleware); a single decision additionally needs the action's own
 * `approve-{action}-{resource}` permission (checked per row here). On approval the original action re-runs
 * over its captured rows via the requester's controller. Registered by Route::adminCoreApprovals().
 */
class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $approvals = Approval::pending()->with('requester')->latest()
            ->paginate((int) config('admin-core.pagination', 20));

        // Flag which rows this user may actually decide, so the inbox shows the buttons only where allowed.
        $approvals->getCollection()->each(fn (Approval $a) => $a->setAttribute('can_decide', $this->canDecide($a, $actor)));

        return view('admin-core::approvals.index', ['approvals' => $approvals]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $approval = $this->pendingOrFail($id);
        $actor = $this->actor($request);
        abort_unless($this->canDecide($approval, $actor), 403);
        // Re-run the original action through the requester's controller (the only place its handler lives).
        abort_unless(is_subclass_of($approval->handler, WebController::class), 422);

        // Claim + execute in ONE transaction. The atomic claim still decides the single winner (a concurrent /
        // double-submitted approve loses it → 404), but wrapping it WITH the action means a throwing handler
        // rolls the claim back too — so the request returns to 'pending' and is retryable, instead of being
        // stuck 'approved' with its effect rolled back and no way to re-run it.
        $result = null;
        DB::transaction(function () use ($approval, $request, $actor, &$result) {
            abort_unless($this->claim($approval, 'approved', $this->note($request), $actor), 404);
            $result = app($approval->handler)->applyApprovedAction($approval->action, $approval->ids());
        });

        $this->notifyRequester($approval, true); // after commit — never notify "approved" for a rolled-back run

        // Surface a silent no-op: the replay resolves the captured ids through the APPROVER's service query()
        // (a fresh controller), so in a tenant/scope-bound app a cross-scope request can affect 0 rows while
        // still being marked 'approved'. Warn the approver instead of a green "approved" that did nothing.
        if (($result['affected'] ?? null) === 0 && $approval->ids() !== []) {
            return back()->with('warning', __('admin-core::admin-core.approvals.approved_no_rows'));
        }

        return back()->with('success', __('admin-core::admin-core.approvals.approved'));
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $approval = $this->pendingOrFail($id);
        $actor = $this->actor($request);
        abort_unless($this->canDecide($approval, $actor), 403);
        abort_unless($this->claim($approval, 'rejected', $this->note($request), $actor), 404);

        $this->notifyRequester($approval, false);

        return back()->with('success', __('admin-core::admin-core.approvals.rejected'));
    }

    /**
     * The deciding user, resolved on the route's approval guard — the portal guard when the inbox was mounted
     * with Route::adminCoreApprovals('merchant'), else the default guard. Resolving the DEFAULT guard's user
     * (auth()->user()) is wrong when the inbox lives in a non-default-guard group: it evaluates the approver,
     * the approve-* gate and the maker-checker block against the wrong (or a null) user.
     */
    private function actor(Request $request)
    {
        $guard = $request->route()?->defaults['acApprovalGuard'] ?? null;

        return auth()->guard($guard)->user();
    }

    /** The decision note as a string or null — `note[]=x` (an array) must not TypeError into a 500. */
    private function note(Request $request): ?string
    {
        $note = $request->input('note');

        return is_string($note) ? $note : null;
    }

    private function pendingOrFail(string $id): Approval
    {
        $approval = Approval::where('uuid', $id)->firstOrFail();
        abort_unless($approval->isPending(), 404); // already decided — fail fast (the atomic claim is the real guard)

        return $approval;
    }

    /**
     * Atomically move this request from pending → $status (recording the decision + approver). Returns false
     * if it was no longer pending (someone else won the race), so the caller can stop before executing.
     */
    private function claim(Approval $approval, string $status, ?string $note, $actor): bool
    {
        $update = [
            'status' => $status,
            'decision_note' => is_string($note) && $note !== '' ? $note : null,
            'decided_at' => now(),
        ];
        if ($actor) {
            $update['approver_type'] = $actor->getMorphClass();
            $update['approver_id'] = $actor->getKey();
        }

        $claimed = Approval::where('uuid', $approval->uuid)->where('status', 'pending')->update($update) === 1;
        if ($claimed) {
            $approval->forceFill($update); // reflect the decision on the in-memory model for the notification
        }

        return $claimed;
    }

    /** May this user decide this request? Needs the action's `approve-{action}-{resource}` permission. */
    private function canDecide(Approval $approval, $actor): bool
    {
        // Segregation of duties (the "maker-checker" this subsystem is named for): the requester can never
        // decide their OWN request, regardless of permissions — otherwise a staff member who later gains the
        // approve permission (an ordinary role change) could self-approve their pending request. Opt out via
        // config for workflows that intentionally allow it.
        if (! config('admin-core.approval.allow_self_approval', false) && $this->isRequester($approval, $actor)) {
            return false;
        }

        if (! config('admin-core.permission.enabled')) {
            return true;
        }

        $base = $approval->resource
            ? str_replace(
                ['{action}', '{resource}'],
                [$approval->action, $approval->resource],
                (string) config('admin-core.permission.pattern', '{action}-{resource}')
            )
            : $approval->action;

        return (bool) $actor?->can('approve-' . $base);
    }

    /** Whether this user is the one who filed this request (maker == checker). */
    private function isRequester(Approval $approval, $actor): bool
    {
        if ($actor === null || $approval->requester_id === null) {
            return false;
        }

        return (string) $approval->requester_id === (string) $actor->getKey()
            && $approval->requester_type === $actor->getMorphClass();
    }

    private function notifyRequester(Approval $approval, bool $approved): void
    {
        $requester = $approval->requester;
        if ($requester && method_exists($requester, 'notify')) {
            $requester->notify(new AdminNotification(
                title: __($approved
                    ? 'admin-core::admin-core.approvals.notify_approved_title'
                    : 'admin-core::admin-core.approvals.notify_rejected_title'),
                message: __('admin-core::admin-core.approvals.notify_decision_message', ['label' => $approval->label()]),
                icon: $approved ? 'bi-check-circle' : 'bi-x-circle',
            ));
        }
    }
}
