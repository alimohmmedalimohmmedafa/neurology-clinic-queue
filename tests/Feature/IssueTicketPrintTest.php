<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueTicketPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_page_prepares_the_ticket_inside_a_hidden_print_area(): void
    {
        $this->get('/queue-number')
            ->assertOk()
            ->assertSee('body class="issue-page"', false)
            ->assertSee('id="ticket-print"', false)
            ->assertSee('class="ticket-print"', false)
            ->assertSee('id="btn-reprint"', false)
            ->assertSee('css/ticket.css')
            ->assertSee('js/issue-ticket.js')
            ->assertSee('إصدار رقم جديد');
    }

    public function test_issue_script_prints_in_place_without_opening_another_page(): void
    {
        $script = file_get_contents(public_path('js/issue-ticket.js'));

        $this->assertStringContainsString("method: 'POST'", $script);
        $this->assertStringContainsString('inFlight', $script);
        $this->assertStringContainsString('pageshow', $script);
        $this->assertStringContainsString('window.print()', $script);
        $this->assertStringContainsString("fetch('/tickets/visits/'", $script);
        $this->assertStringContainsString("querySelector('.ticket')", $script);

        $this->assertStringNotContainsString('window.location', $script);
        $this->assertStringNotContainsString('location.href', $script);
        $this->assertStringNotContainsString('window.open', $script);
        $this->assertStringNotContainsString('target="_blank"', $script);
        $this->assertStringNotContainsString('auto=1', $script);
    }

    public function test_issue_script_creates_exactly_one_visit_per_click(): void
    {
        $script = file_get_contents(public_path('js/issue-ticket.js'));

        $this->assertSame(1, substr_count($script, "fetch('/api/queue/visits'"));
        $this->assertSame(1, substr_count($script, "method: 'POST'"));
    }

    public function test_reprint_never_creates_a_new_visit(): void
    {
        $script = file_get_contents(public_path('js/issue-ticket.js'));

        preg_match('/reprintButton\.addEventListener\(.*?\n        \}\);/s', $script, $match);

        $this->assertNotEmpty($match, 'Reprint handler is missing.');

        $handler = $match[0];

        $this->assertStringContainsString('window.print()', $handler);
        $this->assertStringNotContainsString('fetch(', $handler);
        $this->assertStringNotContainsString('POST', $handler);
        $this->assertStringContainsString('issuedTicket', $handler);
    }

    public function test_print_area_is_filled_from_the_real_ticket_page(): void
    {
        $visit = $this->postJson('/api/queue/visits')->assertCreated()->json();

        $ticket = $this->get('/tickets/visits/'.$visit['id'])
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee('001')
            ->assertSee('المقابلة الأول')
            ->assertSee('التاريخ:')
            ->assertSee('الوقت:');

        preg_match('/<main class="ticket">.*?<\/main>/s', $ticket->getContent(), $match);

        $this->assertNotEmpty($match, 'The ticket page must expose the ticket block.');
        $this->assertStringContainsString('queue-number', $match[0]);
    }

    public function test_review_ticket_block_keeps_the_review_wording(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $ticket = $this->get('/tickets/visits/1')->assertOk();

        preg_match('/<main class="ticket">.*?<\/main>/s', $ticket->getContent(), $match);

        $this->assertNotEmpty($match);
        $this->assertStringContainsString('001', $match[0]);
        $this->assertStringContainsString('مراجعة الطبيب بعد النتيجة', $match[0]);
        $this->assertStringNotContainsString('المقابلة الأول', $match[0]);
    }

    public function test_opening_the_issue_page_or_ticket_page_creates_no_number(): void
    {
        $this->get('/queue-number')->assertOk();
        $this->get('/queue-number')->assertOk();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->get('/tickets/visits/1')->assertOk();
        $this->get('/tickets/visits/1')->assertOk();

        $this->assertDatabaseCount('visits', 1);
    }

    public function test_printing_helpers_do_not_touch_the_queue_logic(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->get('/tickets/visits/1')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['visit_id' => null, 'queue_number' => null, 'stage' => null]);

        $this->getJson('/api/queue/state')
            ->assertOk()
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '001');
    }
}
