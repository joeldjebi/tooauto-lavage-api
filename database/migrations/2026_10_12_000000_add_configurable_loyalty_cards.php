<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lavages', function (Blueprint $table) {
            $table->unsignedSmallInteger('fidelite_total_cases')->default(10);
        });

        Schema::table('fidelites', function (Blueprint $table) {
            $table->string('statut', 20)->default('en_cours');
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('fidelite_passages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fidelite_id');
            $table->unsignedBigInteger('attribution_vehicule_id')->nullable()->unique('fidelite_passages_attribution_unique');
            $table->unsignedBigInteger('type_lavage_id')->nullable();
            $table->enum('source', ['attribution', 'manual'])->default('attribution');
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->foreign('fidelite_id')->references('id')->on('fidelites')->cascadeOnDelete();
            $table->foreign('attribution_vehicule_id')->references('id')->on('attribution_vehicules')->nullOnDelete();
            $table->index(['fidelite_id', 'used_at'], 'fidelite_passages_card_used_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fidelite_passages');

        Schema::table('fidelites', function (Blueprint $table) {
            $table->dropColumn(['statut', 'completed_at']);
        });

        Schema::table('lavages', function (Blueprint $table) {
            $table->dropColumn('fidelite_total_cases');
        });
    }
};
