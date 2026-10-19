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
        Schema::create('queue_controls', function (Blueprint $table) {
            $table->id();
            $table->enum('stage', ['consultation', 'review'])->unique();
            $table->boolean('is_paused')->default(false);
            $table->foreignId('current_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_controls');
    }
};
