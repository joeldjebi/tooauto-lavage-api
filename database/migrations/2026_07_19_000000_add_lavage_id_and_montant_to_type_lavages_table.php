<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_lavages', function (Blueprint $table) {
            $table->foreignId('lavage_id')
                ->nullable()
                ->after('id')
                ->constrained('lavages')
                ->cascadeOnDelete();
            $table->decimal('montant', 10, 2)->default(0)->after('libelle');
            $table->unique(['lavage_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::table('type_lavages', function (Blueprint $table) {
            $table->dropUnique(['lavage_id', 'libelle']);
            $table->dropConstrainedForeignId('lavage_id');
            $table->dropColumn('montant');
        });
    }
};
