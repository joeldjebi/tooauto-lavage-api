<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lavage_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lavage_id');
            $table->string('token', 255)->unique();
            $table->enum('platform', ['android', 'ios', 'web'])->nullable();
            $table->string('device_id', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['lavage_id', 'last_used_at'], 'lavage_fcm_tokens_lavage_used_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lavage_fcm_tokens');
    }
};
