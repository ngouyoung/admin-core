<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Notification Platform's owned in-app store. Hybrid identity: a fast bigint `id` for the PK/joins and a public
// `uuid` for the Center handle. One row per recipient (fan-out). Written only by the InApp channel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();                                   // internal identity
            $table->uuid('uuid')->unique();                 // public handle (Center API/URL)
            $table->morphs('notifiable');                   // notifiable_type + notifiable_id (bigint), indexed
            $table->string('guard')->nullable()->index();   // recipient guard (portal isolation)
            $table->string('type')->index();                // opaque, product-owned type key
            $table->json('data');                           // immutable presentation payload
            $table->timestamp('read_at')->nullable();       // read-state
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);    // unread count / list
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']); // ordered inbox
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
