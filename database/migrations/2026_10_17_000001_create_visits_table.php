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
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('queue_number');
            $table->date('visit_date');
            $table->enum('stage', ['consultation', 'review'])->default('consultation');
            $table->enum('status', ['waiting', 'called', 'skipped', 'completed'])->default('waiting');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['visit_date', 'queue_number']);
            $table->index(['visit_date', 'stage', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
