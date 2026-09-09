<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_builds', function (Blueprint $table): void {
            $table->string('guest_callback_token_hash', 64)->nullable()->unique()->after('builder_type');
            $table->string('guest_callback_url')->nullable()->after('guest_callback_token_hash');
            $table->unsignedBigInteger('guest_last_sequence')->default(0)->after('guest_callback_url');
            $table->string('guest_stage_id')->nullable()->after('guest_last_sequence');
            $table->timestamp('guest_last_callback_at')->nullable()->after('guest_stage_id');
            $table->string('guest_outcome', 16)->nullable()->after('guest_last_callback_at');
            $table->integer('guest_exit_code')->nullable()->after('guest_outcome');
            $table->text('guest_error')->nullable()->after('guest_exit_code');
            $table->timestamp('guest_finalizing_at')->nullable()->after('guest_error');
            $table->boolean('keep_failed_vm')->default(false)->after('guest_last_callback_at');
        });

        Schema::create('image_build_guest_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('image_build_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 32);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['image_build_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_build_guest_events');

        Schema::table('image_builds', function (Blueprint $table): void {
            $table->dropUnique(['guest_callback_token_hash']);
            $table->dropColumn([
                'guest_callback_token_hash',
                'guest_callback_url',
                'guest_last_sequence',
                'guest_stage_id',
                'guest_last_callback_at',
                'guest_outcome',
                'guest_exit_code',
                'guest_error',
                'guest_finalizing_at',
                'keep_failed_vm',
            ]);
        });
    }
};
