<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Independent numbering for examination (result) tickets. The consultation
     * queue keeps its daily "queue_number" untouched; a visit that enters the
     * review stage also gets its own daily "exam_number", printed as F-001,
     * F-002, ... so the exam series always restarts from F-001 on a cleared
     * day while the consultation series restarts from 001.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedInteger('exam_number')->nullable()->after('queue_number');
        });

        DB::statement(
            'CREATE UNIQUE INDEX visits_one_exam_per_day ON visits (visit_date, exam_number) WHERE exam_number IS NOT NULL'
        );

        // Existing review visits keep their printed number as the exam number
        // (007 becomes F-007), so old result tickets reprint the same digits.
        DB::statement(
            "UPDATE visits SET exam_number = queue_number WHERE stage = 'review' AND exam_number IS NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visits_one_exam_per_day');

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('exam_number');
        });
    }
};
