<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dépôt d'articles sans plaque (tapis, moquette, bâche, fauteuil...) : circuit séparé
     * des lavages de véhicules, sans fidélité. Les motos restent des véhicules.
     */
    public function up(): void
    {
        if (!DB::table('type_de_vehicules')->whereRaw('UPPER(libelle) = ?', ['MOTO'])->exists()) {
            DB::table('type_de_vehicules')->insert([
                'libelle' => 'Moto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('type_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('station_lavage_id')->index();
            $table->string('libelle', 200);
            $table->decimal('prix', 10, 2);
            $table->decimal('montant_laveur', 10, 2)->default(0);
            $table->enum('unite', ['piece', 'm2'])->default('piece');
            $table->boolean('statut')->default(true);
            $table->timestamps();

            $table->unique(['station_lavage_id', 'libelle'], 'type_articles_station_libelle_unique');
        });

        Schema::create('depot_articles', function (Blueprint $table) {
            $table->id();
            $table->string('numero_ticket', 30)->nullable()->unique();
            $table->unsignedBigInteger('station_lavage_id')->index();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('client_nom', 255)->nullable();
            $table->string('client_mobile', 20)->nullable()->index();
            $table->enum('statut', ['recu', 'en_cours', 'pret', 'livre', 'annule'])->default('recu');
            $table->timestamp('date_depot')->useCurrent();
            $table->timestamp('date_retrait_prevue')->nullable();
            $table->timestamp('date_retrait')->nullable();
            $table->decimal('montant_total', 10, 2)->default(0);
            $table->decimal('montant_paye', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['station_lavage_id', 'statut', 'date_depot'], 'depot_articles_station_statut_date_idx');
        });

        Schema::create('depot_article_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_article_id')->constrained('depot_articles')->cascadeOnDelete();
            $table->foreignId('type_article_id')->nullable()->constrained('type_articles')->nullOnDelete();
            $table->string('libelle', 200);
            $table->string('description', 255)->nullable();
            $table->decimal('quantite', 10, 2)->default(1);
            $table->enum('unite', ['piece', 'm2'])->default('piece');
            $table->decimal('prix_unitaire', 10, 2);
            $table->decimal('montant_laveur_unitaire', 10, 2)->default(0);
            $table->decimal('montant', 10, 2);
            $table->timestamps();
        });

        Schema::create('depot_article_paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_article_id')->constrained('depot_articles')->cascadeOnDelete();
            $table->enum('type', ['avance', 'solde']);
            $table->decimal('montant', 10, 2);
            $table->enum('mode_paiement', ['espece', 'mobile_money']);
            $table->string('operateur_paiement', 10)->nullable();
            $table->unsignedBigInteger('encaisse_par')->nullable();
            $table->timestamp('date_paiement')->useCurrent();
            $table->timestamps();
        });

        Schema::create('depot_article_laveur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_article_id')->constrained('depot_articles')->cascadeOnDelete();
            $table->unsignedBigInteger('laveur_id')->index();
            $table->timestamps();

            $table->unique(['depot_article_id', 'laveur_id'], 'depot_article_laveur_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depot_article_laveur');
        Schema::dropIfExists('depot_article_paiements');
        Schema::dropIfExists('depot_article_lignes');
        Schema::dropIfExists('depot_articles');
        Schema::dropIfExists('type_articles');
    }
};
