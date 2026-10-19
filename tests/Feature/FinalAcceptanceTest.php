<?php

namespace Tests\Feature;

use App\Enums\VisitStatus;
use App\Models\DisplayState;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- 1. sequential numbers -----------------------------------------

    public function test_scenario_multiple_visits_get_sequential_numbers(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '001']);
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '002']);
        $this->postJson('/api/queue/visits', ['stage' => 'review'])->assertCreated()->assertJson(['queue_number' => '003']);
    }

    // ---- 2. restart numbering on a new day, no duplicates in a day ------

    public function test_scenario_numbering_restarts_next_day_without_duplicates_in_a_day(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '001']);
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '002']);

        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '001']);

        Carbon::setTestNow('2026-10-18 10:00:00');
        $this->expectException(QueryException::class);
        DB::table('visits')->insert([
            'queue_number' => 1,
            'visit_date' => '2026-10-18',
            'stage' => 'consultation',
            'status' => 'waiting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- 3. move to review keeps the number -----------------------------

    public function test_scenario_move_to_review_keeps_the_number(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertOk()
            ->assertJson(['queue_number' => '001', 'stage' => 'review', 'status' => 'waiting']);

        $this->assertSame(1, Visit::count());
        $this->assertDatabaseHas('visits', ['id' => 1, 'queue_number' => 1, 'stage' => 'review']);
    }

    // ---- 4. only one called visit per stage -----------------------------

    public function test_scenario_only_one_called_visit_per_stage(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'Resolve the currently called visit before calling the next one.']);

        $this->expectException(QueryException::class);
        Visit::whereKey(2)->update(['status' => VisitStatus::Called->value]);
    }

    // ---- 5 & 6. skip and complete keep the visit record ------------------

    public function test_scenario_skip_keeps_the_visit_record(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/skip')->assertOk();

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => 'skipped']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => 'skipped']);
    }

    public function test_scenario_complete_keeps_the_visit_record(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $visit = Visit::findOrFail(1);
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertNotNull($visit->completed_at);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => 'completed']);
    }

    // ---- 7. both queues are independent ---------------------------------

    public function test_scenario_both_queues_are_independent(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits', ['stage' => 'review'])->assertCreated();

        $this->postJson('/api/queue/stages/consultation/start')
            ->assertOk()
            ->assertJson(['id' => 1, 'stage' => 'consultation', 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 2, 'stage' => 'review', 'status' => 'waiting']);

        $this->postJson('/api/queue/stages/review/start')
            ->assertOk()
            ->assertJson(['id' => 2, 'stage' => 'review', 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'stage' => 'consultation', 'status' => 'called']);
    }

    // ---- 8. waiting screen updates and survives a reload -----------------

    public function test_scenario_waiting_screen_updates_and_reload_restores_state(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $firstLoad = $this->getJson('/api/queue/display')->assertOk()->json();
        $reload = $this->getJson('/api/queue/display')->assertOk()->json();

        $this->assertSame($firstLoad, $reload);
        $this->assertSame('001', $reload['queue_number']);
        $this->assertSame(1, DisplayState::count(), 'reads must never write display state');

        $this->postJson('/api/queue/visits/1/complete')->assertOk();
        $this->postJson('/api/queue/stages/consultation/call-next')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['visit_id' => 2, 'queue_number' => '002']);
    }

    // ---- 9. no personal data anywhere ----------------------------------

    public function test_scenario_no_personal_data_columns_or_fields(): void
    {
        $columns = Schema::getColumnListing('visits');

        $this->assertEqualsCanonicalizing(
            ['id', 'queue_number', 'exam_number', 'visit_date', 'stage', 'status', 'completed_at', 'created_at', 'updated_at'],
            $columns
        );

        $personal = ['name', 'full_name', 'phone', 'mobile', 'email', 'address', 'national_id', 'age', 'gender', 'notes', 'diagnosis'];
        $this->assertSame([], array_values(array_intersect($columns, $personal)));

        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->assertSame(
            ['id', 'queue_number', 'visit_date', 'stage', 'status', 'completed_at'],
            array_keys($this->getJson('/api/queue/state')->json('stages.consultation.current'))
        );

        $this->assertSame(
            ['visit_id', 'queue_number', 'stage', 'is_paused', 'call_token'],
            array_keys($this->getJson('/api/queue/display')->json())
        );
    }
}
