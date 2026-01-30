<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kubernetes_deployment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->integer('replicas')->default(1);
            $table->string('cpu_limit', 50)->nullable();
            $table->string('memory_limit', 50)->nullable();
            $table->string('cpu_request', 50)->nullable();
            $table->string('memory_request', 50)->nullable();
            $table->boolean('autoscaling_enabled')->default(false);
            $table->integer('min_replicas')->default(1);
            $table->integer('max_replicas')->default(10);
            $table->integer('target_cpu_utilization')->default(80);
            $table->integer('target_memory_utilization')->nullable();
            $table->timestamps();

            // Index for performance on application lookups
            $table->index('application_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kubernetes_deployment_settings');
    }
};
