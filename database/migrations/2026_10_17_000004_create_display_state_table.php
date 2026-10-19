<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('display_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('current_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->enum('stage', ['consultation', 'review'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('display_state');
    }
};
