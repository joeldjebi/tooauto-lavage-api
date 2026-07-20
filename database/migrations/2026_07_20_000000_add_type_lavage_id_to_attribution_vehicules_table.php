<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('attribution_vehicules', 'type_lavage_id')) {
            Schema::table('attribution_vehicules', function (Blueprint $table) {
                $table->foreignId('type_lavage_id')
                    ->nullable()
                    ->after('manager_id')
                    ->constrained('type_lavages')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attribution_vehicules', 'type_lavage_id')) {
            Schema::table('attribution_vehicules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('type_lavage_id');
            });
        }
    }
};
