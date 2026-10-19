<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\DisplayState;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('visits'));
        $this->assertTrue(Schema::hasTable('queue_events'));
        $this->assertTrue(Schema::hasTable('queue_controls'));
        $this->assertTrue(Schema::hasTable('display_state'));

        foreach (['id', 'queue_number', 'visit_date', 'stage', 'status', 'created_at', 'updated_at', 'completed_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('visits', $column), "visits.{$column} missing");
        }

        foreach (['id', 'visit_id', 'event', 'stage', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('queue_events', $column), "queue_events.{$column} missing");
        }

        foreach (['id', 'stage', 'is_paused', 'current_visit_id', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('queue_controls', $column), "queue_controls.{$column} missing");
        }

        foreach (['id', 'current_visit_id', 'stage', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('display_state', $column), "display_state.{$column} missing");
        }
    }

    public function test_visits_default_to_waiting_and_consultation(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ])->refresh();

        $this->assertSame(VisitStage::Consultation, $visit->stage);
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertNull($visit->completed_at);
    }

    public function test_queue_number_must_be_unique_per_visit_date(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $this->expectException(QueryException::class);

        Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);
    }

    public function test_same_queue_number_is_allowed_on_different_dates(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $second = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-18',
        ]);

        $this->assertDatabaseHas('visits', [
            'queue_number' => 1,
            'visit_date' => '2026-10-18',
        ]);
        $this->assertSame(1, $second->queue_number);
    }

    public function test_queue_number_is_kept_when_visit_moves_to_review(): void
    {
        $visit = Visit::create([
            'queue_number' => 7,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Consultation,
        ]);

        $visit->update(['stage' => VisitStage::Review]);

        $this->assertSame(7, $visit->fresh()->queue_number);
        $this->assertSame(VisitStage::Review, $visit->fresh()->stage);
    }

    public function test_queue_number_formats_with_three_digits(): void
    {
        $visit = Visit::create([
            'queue_number' => 5,
            'visit_date' => '2026-10-17',
        ]);

        $this->assertSame('005', $visit->formattedQueueNumber());
    }

    public function test_stage_must_be_consultation_or_review(): void
    {
        $this->expectException(QueryException::class);

        DB::table('visits')->insert([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
            'stage' => 'invalid_stage',
            'status' => 'waiting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_status_must_be_one_of_allowed_values(): void
    {
        $this->expectException(QueryException::class);

        DB::table('visits')->insert([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
            'stage' => 'consultation',
            'status' => 'invalid_status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_visit_has_many_events(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $visit->events()->create([
            'event' => QueueEvent::EVENT_CREATED,
            'stage' => VisitStage::Consultation,
        ]);

        $visit->events()->create([
            'event' => QueueEvent::EVENT_CALLED,
            'stage' => VisitStage::Consultation,
        ]);

        $this->assertCount(2, $visit->events);
        $this->assertSame(QueueEvent::EVENT_CALLED, $visit->events()->latest('id')->first()->event);
    }

    public function test_queue_event_belongs_to_visit(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $event = $visit->events()->create([
            'event' => QueueEvent::EVENT_COMPLETED,
            'stage' => VisitStage::Consultation,
        ]);

        $this->assertTrue($event->visit->is($visit));
    }

    public function test_queue_events_are_cascaded_when_visit_is_deleted(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $visit->events()->create(['event' => QueueEvent::EVENT_CREATED]);
        $visit->events()->create(['event' => QueueEvent::EVENT_CALLED]);

        $visit->delete();

        $this->assertSame(0, QueueEvent::where('visit_id', $visit->id)->count());
    }

    public function test_queue_control_stage_is_unique(): void
    {
        QueueControl::create(['stage' => VisitStage::Consultation]);

        $this->expectException(QueryException::class);

        QueueControl::create(['stage' => VisitStage::Consultation]);
    }

    public function test_queue_control_can_pause_and_track_current_visit(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $control = QueueControl::create([
            'stage' => VisitStage::Consultation,
            'is_paused' => true,
            'current_visit_id' => $visit->id,
        ]);

        $this->assertTrue($control->is_paused);
        $this->assertTrue($control->currentVisit->is($visit));
        $this->assertTrue($visit->queueControls->contains($control));
    }

    public function test_queue_control_current_visit_is_nulled_when_visit_is_deleted(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $control = QueueControl::create([
            'stage' => VisitStage::Consultation,
            'current_visit_id' => $visit->id,
        ]);

        $visit->delete();

        $this->assertNull($control->fresh()->current_visit_id);
    }

    public function test_display_state_tracks_current_visit(): void
    {
        $visit = Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
        ]);

        $state = DisplayState::create([
            'current_visit_id' => $visit->id,
            'stage' => VisitStage::Consultation,
        ]);

        $this->assertTrue($state->currentVisit->is($visit));
        $this->assertSame(VisitStage::Consultation, $state->stage);

        $visit->delete();

        $this->assertNull($state->fresh()->current_visit_id);
    }

    public function test_visit_and_events_can_be_written_in_a_single_transaction(): void
    {
        DB::transaction(function () {
            $visit = Visit::create([
                'queue_number' => 1,
                'visit_date' => '2026-10-17',
            ]);

            $visit->events()->create([
                'event' => QueueEvent::EVENT_CREATED,
                'stage' => $visit->stage,
            ]);
        });

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, QueueEvent::count());
    }

    public function test_transaction_rolls_back_when_event_write_fails(): void
    {
        try {
            DB::transaction(function () {
                $visit = Visit::create([
                    'queue_number' => 1,
                    'visit_date' => '2026-10-17',
                ]);

                DB::table('queue_events')->insert([
                    'visit_id' => $visit->id,
                    'event' => QueueEvent::EVENT_CREATED,
                    'stage' => 'invalid_stage',
                    'created_at' => now(),
                ]);
            });

            $this->fail('Expected QueryException was not thrown.');
        } catch (QueryException $e) {
            // expected: enum constraint on queue_events.stage
        }

        $this->assertSame(0, Visit::count());
        $this->assertSame(0, QueueEvent::count());
    }
}
