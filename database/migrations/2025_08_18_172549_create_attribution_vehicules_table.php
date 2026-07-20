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
        Schema::create('attribution_vehicules', function (Blueprint $table) {
            $table->id();
            $table->string('matricule_vehicule');
            $table->unsignedBigInteger('laveur_id');
            $table->unsignedBigInteger('manager_id');
            $table->foreignId('type_lavage_id')->nullable()->constrained('type_lavages')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('statut', ['en_cours', 'termine', 'annule'])->default('en_cours');
            $table->timestamp('date_attribution');
            $table->timestamp('date_debut');
            $table->timestamp('date_fin')->nullable();
            $table->timestamps();

            // Index
            $table->index('matricule_vehicule');
            $table->index('laveur_id');
            $table->index('manager_id');
            $table->index('type_lavage_id');
            $table->index('statut');
            $table->index('date_attribution');

            // Clés étrangères
            $table->foreign('matricule_vehicule')->references('matricule')->on('vehicules')->onDelete('cascade');
            $table->foreign('laveur_id')->references('id')->on('lavages')->onDelete('cascade');
            $table->foreign('manager_id')->references('id')->on('lavages')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribution_vehicules');
    }
};
