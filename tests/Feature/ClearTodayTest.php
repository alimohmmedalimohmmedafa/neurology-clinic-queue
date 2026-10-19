<?php

namespace Tests\Feature;

use App\Models\DisplayState;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClearTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- 1. the button and its confirmation dialog ---------------------

    public function test_reception_page_shows_the_clear_button_and_confirmation_dialog(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="btn-clear-today"', false)
            ->assertSee('id="clear-today-modal"', false)
            ->assertSee('id="btn-clear-today-confirm"', false)
            ->assertSee('id="btn-clear-today-cancel"', false)
            ->assertSee('مسح بيانات اليوم')
            ->assertSee('تأكيد المسح')
            ->assertSee('إلغاء')
            ->assertSee('هل أنت متأكد من مسح جميع بيانات اليوم؟ سيتم حذف جميع التذاكر والمرضى والطلبات والعدادات الحالية من ذاكرة النظام، ولا يمكن استعادتها بعد التأكيد.')
            // the end-work button stays in place next to it, untouched
            ->assertSee('id="btn-end-work"', false)
            ->assertSee('id="end-work-modal"', false)
            ->assertSee('تأكيد إنهاء العمل');
    }

    public function test_cancel_only_closes_the_dialog_and_the_request_runs_after_confirm(): void
    {
        $script = file_get_contents(public_path('js/reception.js'));

        $this->assertStringContainsString(
            "document.getElementById('btn-clear-today-cancel').addEventListener('click', closeClearTodayModal)",
            $script
        );
        $this->assertStringContainsString(
            "document.getElementById('btn-clear-today-confirm').addEventListener('click', performClearToday)",
            $script
        );
        $this->assertStringContainsString("api('/clear-today', { method: 'POST' })", $script);

        // The very first statement of the confirm handler closes the dialog;
        // the request itself only starts after the confirm button was pressed.
        $this->assertMatchesRegularExpression(
            '/function performClearToday\(\) \{\s*closeClearTodayModal\(\);/',
            $script
        );

        // The cancel handler only hides the dialog: it never issues a request.
        preg_match('/function closeClearTodayModal\(\) \{[^}]*/', $script, $cancelBody);
        $this->assertNotEmpty($cancelBody);
        $this->assertStringNotContainsString('api(', $cancelBody[0]);
        $this->assertStringNotContainsString('fetch(', $cancelBody[0]);

        // After a clear the moment is stored so every open tab drops cached
        // ticket templates before printing them again.
        $this->assertStringContainsString('localStorage.setItem(CLEARED_AT_KEY', $script);
        $this->assertStringContainsString('queue:cleared-at', $script);
        $this->assertStringContainsString('closeTicketWindows', $script);
    }

    // ---- 2. the wipe hits the real state source ------------------------

    public function test_clear_today_wipes_visits_events_turn_and_display(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();
        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->assertSame(2, Visit::count());
        $this->assertGreaterThan(0, QueueEvent::count());

        $this->postJson('/api/queue/clear-today')
            ->assertOk()
            ->assertJsonPath('deleted_visits', 2);

        $this->assertSame(0, Visit::count(), 'the tickets themselves must be deleted');
        $this->assertSame(0, QueueEvent::count(), 'the whole call log of today must be deleted');

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting', [])
            ->assertJsonPath('stages.review.current', null)
            ->assertJsonPath('stages.review.waiting', []);

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJsonPath('visit_id', null)
            ->assertJsonPath('queue_number', null)
            ->assertJsonPath('stage', null);

        $this->assertNull(DisplayState::first()->current_visit_id);
        $this->assertNull(DisplayState::first()->stage);
    }

    public function test_clear_today_never_touches_other_days(): void
    {
        $today = Carbon::now()->startOfDay();

        Carbon::setTestNow($today->copy()->subDay()->setTime(9, 0));
        $yesterday = $this->postJson('/api/queue/visits')->assertCreated()->json();

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/clear-today')
            ->assertOk()
            ->assertJsonPath('deleted_visits', 2);

        $this->assertSame(1, Visit::count());
        $this->assertDatabaseHas('visits', [
            'id' => $yesterday['id'],
            'queue_number' => 1,
            'visit_date' => $today->copy()->subDay()->toDateString(),
        ]);
    }

    public function test_repeated_clear_is_safe(): void
    {
        $this->postJson('/api/queue/clear-today')
            ->assertOk()
            ->assertJsonPath('deleted_visits', 0);

        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/clear-today')
            ->assertOk()
            ->assertJsonPath('deleted_visits', 1);

        $this->postJson('/api/queue/clear-today')
            ->assertOk()
            ->assertJsonPath('deleted_visits', 0);

        $this->assertSame(0, Visit::count());
    }

    // ---- 3. numbering restarts: 001 and F-001 --------------------------

    public function test_first_tickets_after_the_clear_are_001_and_f_001(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->postJson('/api/queue/clear-today')->assertOk();

        $first = $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJsonPath('queue_number', '001')
            ->json();

        $second = $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJsonPath('queue_number', '002')
            ->json();

        // First consultation ticket of the new day prints 001 only.
        $this->get('/tickets/visits/'.$first['id'])
            ->assertOk()
            ->assertSee('<div class="queue-number">001</div>', false);

        // First examination ticket of the new day prints F-001.
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/'.$first['id'].'/move-to-review')->assertOk();

        $this->get('/tickets/visits/'.$first['id'])
            ->assertOk()
            ->assertSee('<div class="queue-number">F-001</div>', false);

        // The second examination ticket is F-002: never a duplicate.
        $this->postJson('/api/queue/stages/consultation/call-next')->assertOk();
        $this->postJson('/api/queue/visits/'.$second['id'].'/move-to-review')->assertOk();

        $this->get('/tickets/visits/'.$second['id'].'?review=1')
            ->assertOk()
            ->assertSee('<div class="queue-number">F-002</div>', false);
    }

    public function test_exam_numbers_cannot_repeat_within_a_day(): void
    {
        $today = now()->toDateString();

        Visit::create([
            'queue_number' => 1,
            'exam_number' => 1,
            'visit_date' => $today,
            'stage' => \App\Enums\VisitStage::Review,
            'status' => \App\Enums\VisitStatus::Waiting,
        ]);

        $this->expectException(QueryException::class);

        Visit::create([
            'queue_number' => 2,
            'exam_number' => 1,
            'visit_date' => $today,
            'stage' => \App\Enums\VisitStage::Review,
            'status' => \App\Enums\VisitStatus::Waiting,
        ]);
    }

    // ---- 4. old tickets can never be printed again ---------------------

    public function test_old_tickets_disappear_after_the_clear(): void
    {
        $old = $this->postJson('/api/queue/visits')->assertCreated()->json();

        $this->get('/tickets/visits/'.$old['id'])
            ->assertOk()
            ->assertSee('<div class="queue-number">001</div>', false);

        $this->postJson('/api/queue/clear-today')->assertOk();

        // The old ticket page itself is gone: printing can only ever load
        // a visit that still exists.
        $this->get('/tickets/visits/'.$old['id'])->assertNotFound();
    }

    public function test_print_scripts_empty_the_template_before_every_new_ticket(): void
    {
        $issue = file_get_contents(public_path('js/issue-ticket.js'));

        $this->assertStringContainsString("printArea.innerHTML = '';", $issue);
        $this->assertStringContainsString('queue:cleared-at', $issue);
        $this->assertStringContainsString('dropIssuedTicket', $issue);

        $today = file_get_contents(public_path('js/today-patients.js'));

        $this->assertStringContainsString("printArea.innerHTML = '';", $today);
    }

    // ---- 5. the clear stays independent of the work session ------------

    public function test_clear_keeps_the_session_state_and_end_work_still_works(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        QueueControl::where('stage', 'consultation')->update(['is_paused' => true]);

        $this->postJson('/api/queue/clear-today')->assertOk();

        // The work session itself was not touched: pause flag unchanged and
        // no "session closed" events invented by the clear.
        $this->assertTrue(
            QueueControl::where('stage', 'consultation')->first()->is_paused
        );
        $this->assertSame(
            0,
            QueueEvent::where('event', QueueEvent::EVENT_SESSION_CLOSED)->count()
        );

        // The end-work flow runs exactly as before, right after a clear.
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/end-work')
            ->assertOk()
            ->assertJsonPath('closed_visits', 1)
            ->assertJsonPath('already_ended', false);
    }

    public function test_issuing_stays_blocked_by_the_existing_rules_only(): void
    {
        $this->postJson('/api/queue/clear-today')->assertOk();

        // The clear never switches the session off: issuing keeps working
        // with the exact same rules the system had before.
        $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJsonPath('queue_number', '001');
    }
}
