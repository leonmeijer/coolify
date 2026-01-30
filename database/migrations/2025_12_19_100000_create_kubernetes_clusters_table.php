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
        Schema::create('kubernetes_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->enum('cluster_type', ['kubernetes', 'k3s', 'okd', 'openshift'])->default('kubernetes');
            $table->string('api_server_url', 500);
            $table->longText('kubeconfig'); // Encrypted via model cast
            $table->string('context_name')->nullable();
            $table->string('default_namespace')->default('default');
            $table->boolean('is_reachable')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('team_id');
            $table->index('is_reachable');
            $table->index('cluster_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kubernetes_clusters');
    }
};
