<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attribution_vehicules', 'type_lavage')) {
            Schema::table('attribution_vehicules', function (Blueprint $table) {
                $table->dropColumn('type_lavage');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('attribution_vehicules', 'type_lavage')) {
            Schema::table('attribution_vehicules', function (Blueprint $table) {
                $table->enum('type_lavage', ['interieur', 'exterieur', 'complet', 'premium'])->nullable();
            });
        }
    }
};
