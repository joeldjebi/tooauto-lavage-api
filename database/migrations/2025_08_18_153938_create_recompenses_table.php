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
        Schema::create('recompenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usager_id');
            $table->unsignedBigInteger('lavage_id');
            $table->string('matricule_vehicule', 20);
            $table->enum('type_recompense', [
                'lavage_gratuit',
                'reduction_50',
                'reduction_25',
                'bonus_points',
                'service_premium',
                'cadeau'
            ])->default('lavage_gratuit');
            $table->string('description')->nullable();
            $table->decimal('valeur', 10, 2)->default(0.00);
            $table->enum('statut', [
                'attribuee',
                'utilisee',
                'expiree',
                'annulee'
            ])->default('attribuee');
            $table->timestamp('date_attribution')->useCurrent();
            $table->timestamp('date_utilisation')->nullable();
            $table->boolean('utilisee')->default(false);
            $table->timestamps();

            // Index
            $table->index('usager_id');
            $table->index('lavage_id');
            $table->index('matricule_vehicule');
            $table->index('type_recompense');
            $table->index('statut');
            $table->index('date_attribution');
            $table->index('utilisee');

            // Contraintes de clés étrangères
            $table->foreign('usager_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('lavage_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('matricule_vehicule')->references('matricule')->on('vehicules')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recompenses');
    }
};
