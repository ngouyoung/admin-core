<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
    public function index(): View
    {
        $approvals = Approval::pending()->with('requester')->latest()
            ->paginate((int) config('admin-core.pagination', 20));

        // Flag which rows this user may actually decide, so the inbox shows the buttons only where allowed.
        $approvals->getCollection()->each(fn (Approval $a) => $a->setAttribute('can_decide', $this->canDecide($a)));

        return view('admin-core::approvals.index', ['approvals' => $approvals]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $approval = $this->pendingOrFail($id);
        abort_unless($this->canDecide($approval), 403);
        // Re-run the original action through the requester's controller (the only place its handler lives).
        abort_unless(is_subclass_of($approval->handler, WebController::class), 422);

        // Atomically claim the request BEFORE executing — the DB decides the single winner, so a concurrent
        // or double-submitted approve can't run a (non-idempotent) action twice. Lose the claim → already
        // decided → 404.
        abort_unless($this->claim($approval, 'approved', $request->input('note')), 404);

        app($approval->handler)->applyApprovedAction($approval->action, $approval->ids());
        $this->notifyRequester($approval, true);

        return back()->with('success', __('admin-core::admin-core.approvals.approved'));
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $approval = $this->pendingOrFail($id);
        abort_unless($this->canDecide($approval), 403);
        abort_unless($this->claim($approval, 'rejected', $request->input('note')), 404);

        $this->notifyRequester($approval, false);

        return back()->with('success', __('admin-core::admin-core.approvals.rejected'));
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
    private function claim(Approval $approval, string $status, ?string $note): bool
    {
        $update = [
            'status' => $status,
            'decision_note' => is_string($note) && $note !== '' ? $note : null,
            'decided_at' => now(),
        ];
        if ($user = auth()->user()) {
            $update['approver_type'] = $user->getMorphClass();
            $update['approver_id'] = $user->getKey();
        }

        $claimed = Approval::where('uuid', $approval->uuid)->where('status', 'pending')->update($update) === 1;
        if ($claimed) {
            $approval->forceFill($update); // reflect the decision on the in-memory model for the notification
        }

        return $claimed;
    }

    /** May the current user decide this request? Needs the action's `approve-{action}-{resource}` permission. */
    private function canDecide(Approval $approval): bool
    {
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

        return (bool) auth()->user()?->can('approve-' . $base);
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
