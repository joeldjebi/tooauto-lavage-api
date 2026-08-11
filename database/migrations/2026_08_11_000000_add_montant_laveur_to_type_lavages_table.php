<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_lavages', function (Blueprint $table) {
            $table->decimal('montant_laveur', 10, 2)->default(0)->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('type_lavages', function (Blueprint $table) {
            $table->dropColumn('montant_laveur');
        });
    }
};
