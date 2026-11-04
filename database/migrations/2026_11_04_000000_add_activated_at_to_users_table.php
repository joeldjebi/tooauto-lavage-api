<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un usager créé par un tiers (lavage, commercial, station, garage...) n'est qu'un client
     * tant qu'il ne s'est pas connecté / inscrit sur l'app usager : activated_at reste alors NULL.
     * Par défaut (inscription via l'app), activated_at vaut la date de création.
     * Le créateur se déduit des colonnes lavage_id, commercial_id, station_id, etc.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->useCurrent()->after('commercial_id');
                $table->index('activated_at', 'users_activated_at_index');
            }
        });

        // Reprise de l'existant
        $creatorColumns = ['lavage_id', 'commercial_id', 'station_id', 'station_service_id', 'professionnel_id', 'etablissement_id'];

        DB::table('users')
            ->where(function ($query) use ($creatorColumns) {
                foreach ($creatorColumns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->update(['activated_at' => null]);

        DB::table('users')
            ->where(function ($query) use ($creatorColumns) {
                foreach ($creatorColumns as $column) {
                    $query->whereNull($column);
                }
            })
            ->update(['activated_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'activated_at')) {
                $table->dropIndex('users_activated_at_index');
                $table->dropColumn('activated_at');
            }
        });
    }
};
