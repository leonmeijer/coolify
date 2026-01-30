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
        Schema::create('kubernetes_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->foreignId('kubernetes_cluster_id')->constrained()->cascadeOnDelete();
            $table->string('namespace');
            $table->string('default_cpu_limit', 50)->default('500m');
            $table->string('default_memory_limit', 50)->default('512Mi');
            $table->string('default_cpu_request', 50)->default('100m');
            $table->string('default_memory_request', 50)->default('128Mi');
            $table->integer('default_replicas')->default(1);
            $table->string('ingress_class')->nullable();
            $table->string('storage_class')->nullable();
            $table->timestamps();

            // Unique constraint on (kubernetes_cluster_id, namespace)
            $table->unique(['kubernetes_cluster_id', 'namespace'], 'unique_cluster_namespace');

            // Indexes for performance
            $table->index('kubernetes_cluster_id');
            $table->index('namespace');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kubernetes_destinations');
    }
};
