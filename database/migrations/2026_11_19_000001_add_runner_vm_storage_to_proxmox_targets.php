<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxmox_targets', function (Blueprint $table) {
            $table->string('runner_vm_storage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proxmox_targets', function (Blueprint $table) {
            $table->dropColumn('runner_vm_storage');
        });
    }
};
