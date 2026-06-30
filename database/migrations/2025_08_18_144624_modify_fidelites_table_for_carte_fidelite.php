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
        Schema::table('fidelites', function (Blueprint $table) {
            // Renommer la colonne points en cases_remplies pour plus de clarté
            $table->renameColumn('points', 'cases_remplies');

            // Ajouter le matricule du véhicule
            $table->string('matricule_vehicule', 20)->after('lavage_id');

            // Ajouter les nouveaux champs pour la carte de fidélité
            $table->integer('total_cases')->default(10)->after('matricule_vehicule');
            $table->integer('recompenses_gagnees')->default(0)->after('total_cases');
            $table->timestamp('derniere_recompense')->nullable()->after('recompenses_gagnees');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fidelites', function (Blueprint $table) {
            // Supprimer les nouveaux champs
            $table->dropColumn(['total_cases', 'recompenses_gagnees', 'derniere_recompense', 'matricule_vehicule']);

            // Remettre l'ancien nom de colonne
            $table->renameColumn('cases_remplies', 'points');
        });
    }
};
