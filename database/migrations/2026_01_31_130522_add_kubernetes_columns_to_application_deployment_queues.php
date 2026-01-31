<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds Kubernetes-specific columns to the application_deployment_queues table
     * to support deployments to Kubernetes clusters.
     */
    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            // Reference to the Kubernetes cluster for this deployment
            // Nullable because Docker deployments don't use this
            $table->foreignId('kubernetes_cluster_id')
                ->nullable()
                ->after('build_server_id')
                ->constrained('kubernetes_clusters')
                ->nullOnDelete();

            // The Kubernetes namespace for this deployment
            // Stored here to capture the namespace at deployment time
            $table->string('kubernetes_namespace')
                ->nullable()
                ->after('kubernetes_cluster_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['kubernetes_cluster_id']);

            // Then drop the columns
            $table->dropColumn(['kubernetes_cluster_id', 'kubernetes_namespace']);
        });
    }
};
