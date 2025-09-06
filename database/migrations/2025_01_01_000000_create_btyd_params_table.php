<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('btyd_params', function (Blueprint $table) {
            $table->id();
            $table->string('model')->unique(); // 'bgnbd' or 'gamma_gamma'
            $table->json('params');
            $table->timestamp('fitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btyd_params');
    }
};
