<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            $table->id()->change();
        });
    }

    public function down()
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            $table->dropColumn('id');
        });
    }
};
