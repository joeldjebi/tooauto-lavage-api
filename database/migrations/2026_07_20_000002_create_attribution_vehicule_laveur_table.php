<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_vehicule_laveur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribution_vehicule_id')->constrained('attribution_vehicules')->cascadeOnDelete();
            $table->foreignId('laveur_id')->constrained('lavages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['attribution_vehicule_id', 'laveur_id'], 'attribution_laveur_unique');
            $table->index('laveur_id');
        });

        DB::table('attribution_vehicules')
            ->whereNotNull('laveur_id')
            ->orderBy('id')
            ->select(['id', 'laveur_id', 'created_at', 'updated_at'])
            ->chunk(500, function ($attributions) {
                $rows = [];

                foreach ($attributions as $attribution) {
                    $rows[] = [
                        'attribution_vehicule_id' => $attribution->id,
                        'laveur_id' => $attribution->laveur_id,
                        'created_at' => $attribution->created_at ?? now(),
                        'updated_at' => $attribution->updated_at ?? now(),
                    ];
                }

                DB::table('attribution_vehicule_laveur')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_vehicule_laveur');
    }
};
