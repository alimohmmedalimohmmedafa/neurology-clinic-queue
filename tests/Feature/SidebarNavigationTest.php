<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = [
        'queue-number' => 'إصدار رقم انتظار',
        'consultation-queue' => 'طابور المقابلة',
        'review-queue' => 'طابور النتيجة',
        'patients/today' => 'جميع مرضى اليوم',
        'waiting' => 'شاشة العرض',
    ];

    private const PAGES = [
        '/' => 'استقبال — نظام الطابور',
        '/queue-number' => 'إصدار رقم انتظار — نظام الطابور',
        '/consultation-queue' => 'طابور المقابلة — نظام الطابور',
        '/review-queue' => 'طابور النتيجة — نظام الطابور',
        '/patients/today' => 'جميع مرضى اليوم — نظام الطابور',
        '/waiting' => 'شاشة العرض — نظام الطابور',
    ];

    private const ACTIVE_SECTION = [
        '/' => ['href' => '/', 'label' => 'الاستقبال'],
        '/queue-number' => ['href' => '/queue-number', 'label' => 'إصدار رقم انتظار'],
        '/consultation-queue' => ['href' => '/consultation-queue', 'label' => 'طابور المقابلة'],
        '/review-queue' => ['href' => '/review-queue', 'label' => 'طابور النتيجة'],
        '/patients/today' => ['href' => '/patients/today', 'label' => 'جميع مرضى اليوم'],
    ];

    public function test_sidebar_lists_every_required_section_in_order(): void
    {
        $html = $this->get('/queue-number')->assertOk()->getContent();

        $positions = [];

        foreach (self::SECTIONS as $label) {
            $position = mb_strpos($html, '>'.$label.'</a>');
            $this->assertNotFalse($position, 'Sidebar link missing: '.$label);
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Sidebar links are not in the requested order.');
    }

    public function test_every_page_shows_all_sidebar_sections(): void
    {
        foreach (['/', '/queue-number', '/consultation-queue', '/review-queue', '/patients/today'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            foreach (self::SECTIONS as $uri => $label) {
                $candidates = $uri === 'waiting'
                    ? ['href="/waiting" target="_blank" rel="noopener">'.$label.'</a>']
                    : [
                        sprintf('href="/%s">%s</a>', $uri, $label),
                        sprintf('href="/%s" aria-current="page">%s</a>', $uri, $label),
                    ];

                $found = false;

                foreach ($candidates as $candidate) {
                    $found = $found || str_contains($html, $candidate);
                }

                $this->assertTrue($found, 'Missing sidebar link "'.$label.'" on '.$page);
            }

            $this->assertSame(1, substr_count($html, 'target="_blank"'), $page.' must offer one new-tab link only.');
        }
    }

    public function test_each_sidebar_link_opens_a_different_correct_page(): void
    {
        $bodies = [];

        foreach (self::PAGES as $uri => $title) {
            $response = $this->get($uri)->assertOk();
            $response->assertSee($title);

            if ($uri === '/waiting') {
                $response->assertDontSee('sidebar-link', false);
            } else {
                $response->assertSee('sidebar-nav', false);
            }

            $bodies[$uri] = $response->getContent();
        }

        $this->assertCount(count(self::PAGES), array_unique($bodies), 'Two links render the same page.');
    }

    public function test_only_the_current_section_is_marked_active(): void
    {
        foreach (self::ACTIVE_SECTION as $page => $section) {
            $html = $this->get($page)->assertOk()->getContent();

            $active = sprintf(
                'class="sidebar-link active" href="%s" aria-current="page">%s</a>',
                $section['href'],
                $section['label']
            );

            $this->assertStringContainsString($active, $html, 'Active section not highlighted on '.$page);
            $this->assertSame(1, substr_count($html, 'sidebar-link active'), 'Wrong active count on '.$page);
            $this->assertSame(1, substr_count($html, 'aria-current="page"'), 'Wrong aria-current count on '.$page);
        }
    }

    public function test_every_section_offers_a_way_back_to_the_reception_home(): void
    {
        foreach (['/queue-number', '/consultation-queue', '/review-queue', '/patients/today'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            $this->assertStringContainsString('class="sidebar-brand" href="/"', $html);
            $this->assertStringContainsString('class="sidebar-link" href="/">الاستقبال</a>', $html);
        }
    }

    public function test_display_link_is_the_only_one_opening_a_new_tab(): void
    {
        $html = $this->get('/patients/today')->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="/waiting" target="_blank" rel="noopener">شاشة العرض</a>',
            $html
        );
        $this->assertStringNotContainsString('target="_blank"', str_replace(
            'href="/waiting" target="_blank" rel="noopener">شاشة العرض</a>',
            '',
            $html
        ));
    }
}
