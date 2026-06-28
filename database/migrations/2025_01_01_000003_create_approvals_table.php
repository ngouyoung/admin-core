<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests: a staff member runs an `->requiresApproval()` table action they may request but not
 * approve; the request is held here until an approver decides. On approval the original action re-runs over
 * the captured rows. Polymorphic requester/approver so it works across any user model / guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('approvals')) {
            return;
        }

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('requester'); // who asked
            $table->nullableMorphs('approver');  // who decided (null until decided)
            $table->string('action');            // the Action key, e.g. 'mark-paid'
            $table->string('resource')->nullable(); // resource slug for permission/display, e.g. 'order'
            $table->string('handler');           // controller FQCN that can re-run the action
            $table->json('payload');             // {ids: [...], label: '...'}
            $table->string('status')->default('pending')->index(); // pending | approved | rejected
            $table->text('note')->nullable();          // requester's reason
            $table->text('decision_note')->nullable(); // approver's reason
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
