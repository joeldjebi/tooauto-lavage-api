<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('parrains', function (Blueprint $table) {
            $table->string('code', 8)->change();
        });
    }

    public function down()
    {
        Schema::table('parrains', function (Blueprint $table) {
            $table->integer('code')->change();
        });
    }
};