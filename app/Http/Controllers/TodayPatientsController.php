<?php

namespace App\Http\Controllers;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\QueueControl;
use App\Models\Visit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TodayPatientsController extends Controller
{
    /**
     * List every visit of the current day with the actions the reception
     * staff may run for each one. Read-only: rendering never creates
     * control rows and never deletes or moves a visit on its own.
     */
    public function index(Request $request): View
    {
        $date = now()->toDateString();

        $search = trim((string) $request->query('search', ''));
        $stage = VisitStage::tryFrom((string) $request->query('stage', ''));
        $status = VisitStatus::tryFrom((string) $request->query('status', ''));

        $query = Visit::query()
            ->where('visit_date', $date)
            ->orderBy('queue_number');

        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            $query->where('queue_number', (int) ($digits === '' ? 0 : $digits));
        }

        if ($stage !== null) {
            $query->where('stage', $stage->value);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $occupied = QueueControl::whereIn('stage', [
            VisitStage::Consultation->value,
            VisitStage::Review->value,
        ])->whereNotNull('current_visit_id')->pluck('current_visit_id', 'stage');

        return view('today_patients', [
            'date' => $date,
            'rows' => $query->get()
                ->map(fn (Visit $visit) => $this->present($visit, $occupied))
                ->values(),
            'search' => $search,
            'stage' => $stage,
            'status' => $status,
        ]);
    }

    private function present(Visit $visit, Collection $occupied): array
    {
        return [
            'id' => $visit->id,
            'number' => $visit->formattedQueueNumber(),
            'stage_label' => $visit->stage === VisitStage::Consultation ? 'المقابلة' : 'النتيجة',
            'status_label' => match ($visit->status) {
                VisitStatus::Waiting => 'منتظر',
                VisitStatus::Called => 'تم استدعاؤه',
                VisitStatus::Skipped => 'تم تجاوزه',
                VisitStatus::Completed => 'مكتملة',
            },
            'status_class' => match ($visit->status) {
                VisitStatus::Waiting => 'st-waiting',
                VisitStatus::Called => 'st-called',
                VisitStatus::Skipped => 'st-skipped',
                VisitStatus::Completed => 'st-completed',
            },
            'registered_at' => $visit->created_at->format('Y-m-d H:i'),
            'actions' => $this->actionsFor($visit, $occupied),
        ];
    }

    /**
     * Only the actions that match both the visit state and the current
     * queue rules are rendered, so no action can ever be shown twice or
     * out of place.
     */
    private function actionsFor(Visit $visit, Collection $occupied): array
    {
        $actions = [];

        if ($visit->status === VisitStatus::Waiting && ! $occupied->has($visit->stage->value)) {
            $actions[] = ['endpoint' => 'call', 'label' => 'استدعاء المريض', 'class' => 'btn-primary'];
        }

        if ($visit->status === VisitStatus::Called) {
            $actions[] = ['endpoint' => 'complete', 'label' => 'إنهاء الزيارة', 'class' => 'btn-success'];
        }

        // Any consultation visit of today can move to the review queue: a
        // waiting one, the called one, and even a completed one when the
        // examination sheet is handed in late. Visits that already sit in the
        // review queue never offer the transfer again, so a duplicate transfer
        // (or a second ticket) is impossible; they get the reprint action.
        if ($visit->stage === VisitStage::Consultation
            && in_array($visit->status, [VisitStatus::Waiting, VisitStatus::Called, VisitStatus::Completed], true)) {
            $actions[] = ['endpoint' => 'move-to-review', 'label' => 'تحويل إلى طابور النتيجة', 'class' => 'btn-info'];
        }

        // Review tickets can always be printed again with the original queue
        // number, without moving the visit or issuing a new one.
        if ($visit->stage === VisitStage::Review
            && in_array($visit->status, [VisitStatus::Waiting, VisitStatus::Called], true)) {
            $actions[] = ['type' => 'print', 'label' => 'إعادة طباعة تذكرة النتيجة', 'class' => 'btn-secondary'];
        }

        if ($visit->status === VisitStatus::Skipped) {
            $actions[] = ['endpoint' => 'restore', 'label' => 'إعادة إلى الانتظار', 'class' => 'btn'];
        }

        return $actions;
    }
}
