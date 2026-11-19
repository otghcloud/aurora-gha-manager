<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_template_target', function (Blueprint $table): void {
            $table->string('imported_storage')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runner_template_target', function (Blueprint $table): void {
            $table->dropColumn(['imported_storage', 'imported_at']);
        });
    }
};
