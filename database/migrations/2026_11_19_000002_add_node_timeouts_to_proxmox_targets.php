<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxmox_targets', function (Blueprint $table) {
            $table->unsignedInteger('proxmox_api_timeout')->default(120);
            $table->unsignedInteger('proxmox_import_timeout')->default(3600);
            $table->unsignedInteger('proxmox_task_timeout')->default(3600);
            $table->unsignedInteger('proxmox_shutdown_timeout')->default(300);
            $table->unsignedInteger('cloudimage_guest_ip_timeout')->default(600);
            $table->unsignedInteger('cloudimage_ssh_timeout')->default(600);
            $table->unsignedInteger('cloudimage_build_timeout')->default(43200);
        });
    }

    public function down(): void
    {
        Schema::table('proxmox_targets', function (Blueprint $table) {
            $table->dropColumn([
                'proxmox_api_timeout',
                'proxmox_import_timeout',
                'proxmox_task_timeout',
                'proxmox_shutdown_timeout',
                'cloudimage_guest_ip_timeout',
                'cloudimage_ssh_timeout',
                'cloudimage_build_timeout',
            ]);
        });
    }
};
