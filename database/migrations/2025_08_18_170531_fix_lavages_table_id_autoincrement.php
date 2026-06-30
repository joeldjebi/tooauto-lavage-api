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
        // D'abord, ajouter la clé primaire
        DB::statement('ALTER TABLE lavages ADD PRIMARY KEY (id)');

        // Ensuite, modifier la colonne pour ajouter AUTO_INCREMENT
        DB::statement('ALTER TABLE lavages MODIFY id BIGINT(20) NOT NULL AUTO_INCREMENT');

        // Ajouter les index uniques
        try {
            DB::statement('ALTER TABLE lavages ADD UNIQUE KEY unique_mobile (mobile)');
        } catch (\Exception $e) {
            // L'index existe peut-être déjà
        }

        try {
            DB::statement('ALTER TABLE lavages ADD UNIQUE KEY unique_email (email)');
        } catch (\Exception $e) {
            // L'index existe peut-être déjà
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer les index uniques
        try {
            DB::statement('ALTER TABLE lavages DROP INDEX unique_mobile');
        } catch (\Exception $e) {
            // L'index n'existe peut-être pas
        }

        try {
            DB::statement('ALTER TABLE lavages DROP INDEX unique_email');
        } catch (\Exception $e) {
            // L'index n'existe peut-être pas
        }

        // Supprimer la clé primaire
        DB::statement('ALTER TABLE lavages DROP PRIMARY KEY');

        // Remettre l'ID sans AUTO_INCREMENT
        DB::statement('ALTER TABLE lavages MODIFY id BIGINT(20) NOT NULL');
    }
};
