<?php

namespace App\Http\Controllers;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\DisplayState;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Illuminate\Database\Exceptions\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    /**
     * Read-only snapshot of both stages for the reception screen: paused
     * flag, the currently called visit and today's waiting visits. Never
     * writes to the database (no control rows are created on read).
     */
    public function state(): JsonResponse
    {
        $today = now()->toDateString();

        $stages = [];

        foreach ([VisitStage::Consultation, VisitStage::Review] as $stage) {
            $control = QueueControl::where('stage', $stage->value)->first();

            $current = $control?->current_visit_id
                ? Visit::find($control->current_visit_id)
                : null;

            $waiting = Visit::query()
                ->where('stage', $stage->value)
                ->where('status', VisitStatus::Waiting->value)
                ->where('visit_date', $today)
                ->orderBy('queue_number')
                ->get();

            $stages[$stage->value] = [
                'is_paused' => (bool) ($control?->is_paused ?? false),
                'current' => $current !== null ? $this->present($current) : null,
                'waiting' => $waiting->map(fn (Visit $visit) => $this->present($visit))->values()->all(),
            ];
        }

        return response()->json([
            'date' => $today,
            'stages' => $stages,
        ]);
    }

    /**
     * Read-only management snapshot of today's visits for a single stage.
     * Used by the stage queue pages (e.g. the consultation queue): it lists
     * every visit of that stage only, with its number, status and the time
     * it was registered. Never writes to the database.
     */
    public function stageVisits(string $stage): JsonResponse
    {
        $stage = $this->resolveStage($stage);

        $visits = Visit::query()
            ->where('visit_date', now()->toDateString())
            ->where('stage', $stage->value)
            ->orderBy('queue_number')
            ->get();

        return response()->json([
            'date' => now()->toDateString(),
            'stage' => $stage->value,
            'visits' => $visits->map(fn (Visit $visit) => [
                'id' => $visit->id,
                'queue_number' => $visit->formattedQueueNumber(),
                'status' => $visit->status->value,
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
                'registered_at' => $visit->created_at->format('H:i'),
            ])->values()->all(),
        ]);
    }

    /**
     * Read-only snapshot for the waiting-room screen: the last called visit
     * from the approved display state, plus the paused flag of its stage.
     * Never writes to the database.
     */
    public function display(): JsonResponse
    {
        $state = DisplayState::query()->orderBy('id')->first();
        $visit = $state?->currentVisit;

        if ($visit === null) {
            return response()->json([
                'visit_id' => null,
                'queue_number' => null,
                'stage' => null,
                'is_paused' => false,
                'call_token' => null,
            ]);
        }

        $isPaused = $state->stage !== null && QueueControl::where('stage', $state->stage->value)->value('is_paused');

        // A token that changes on every real call, and only then. The waiting
        // screen rings exactly once per call and never on a plain poll.
        $callToken = $state->updated_at === null
            ? null
            : $state->updated_at->getTimestamp().'-'.$visit->id;

        return response()->json([
            'visit_id' => $visit->id,
            'queue_number' => $visit->formattedQueueNumber(),
            'stage' => $state->stage?->value,
            'is_paused' => (bool) $isPaused,
            'call_token' => $callToken,
        ]);
    }

    /**
     * Register a new visit. The daily queue number starts at 001 and is
     * shared across both stages for the same day.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'stage' => ['nullable', 'string', 'in:consultation,review'],
        ]);

        $stage = VisitStage::from($request->input('stage', VisitStage::Consultation->value));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $visit = DB::transaction(function () use ($stage) {
                    $today = now()->toDateString();
                    $nextNumber = ((int) Visit::where('visit_date', $today)->max('queue_number')) + 1;

                    $attributes = [
                        'queue_number' => $nextNumber,
                        'visit_date' => $today,
                        'stage' => $stage,
                        'status' => VisitStatus::Waiting,
                    ];

                    if ($stage === VisitStage::Review) {
                        $attributes['exam_number'] = $this->nextExamNumber($today);
                    }

                    $visit = Visit::create($attributes);

                    $visit->events()->create([
                        'event' => QueueEvent::EVENT_CREATED,
                        'stage' => $stage,
                    ]);

                    return $visit;
                });

                return response()->json($this->present($visit), 201);
            } catch (UniqueConstraintViolationException) {
                // Another request took this number; recompute and retry.
                continue;
            }
        }

        abort(500, 'Could not allocate a queue number after multiple attempts.');
    }

    /**
     * Start a queue stage, or call the next waiting visit, by calling the
     * lowest-numbered waiting visit of today. Same behaviour for both routes.
     */
    public function start(string $stage): JsonResponse
    {
        return $this->callNext($stage);
    }

    /**
     * Call the next waiting visit for a stage. Only allowed when no visit is
     * currently in "called" status.
     */
    public function callNext(string $stage): JsonResponse
    {
        $stage = $this->resolveStage($stage);

        $visit = DB::transaction(function () use ($stage) {
            $control = $this->controlFor($stage);

            if ($control->current_visit_id !== null) {
                abort(409, 'Resolve the currently called visit before calling the next one.');
            }

            $next = $this->nextWaiting($stage);

            if ($next === null) {
                abort(409, 'No waiting visits in this queue.');
            }

            $this->callVisit($control, $next);

            return $next->fresh();
        });

        return response()->json($this->present($visit));
    }

    /**
     * Complete the currently called visit. The visit record is kept.
     */
    public function complete(Visit $visit): JsonResponse
    {
        DB::transaction(function () use ($visit) {
            if ($visit->status !== VisitStatus::Called) {
                abort(409, 'Only a called visit can be completed.');
            }

            $visit->update([
                'status' => VisitStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->releaseStage($visit);

            $visit->events()->create([
                'event' => QueueEvent::EVENT_COMPLETED,
                'stage' => $visit->stage,
            ]);
        });

        return response()->json($this->present($visit->fresh()));
    }

    /**
     * Skip the currently called visit. The visit record is kept.
     */
    public function skip(Visit $visit): JsonResponse
    {
        DB::transaction(function () use ($visit) {
            if ($visit->status !== VisitStatus::Called) {
                abort(409, 'Only a called visit can be skipped.');
            }

            $visit->update(['status' => VisitStatus::Skipped]);

            $this->releaseStage($visit);

            $visit->events()->create([
                'event' => QueueEvent::EVENT_SKIPPED,
                'stage' => $visit->stage,
            ]);
        });

        return response()->json($this->present($visit->fresh()));
    }

    /**
     * Manually move a consultation visit of today into the review queue.
     * Waiting, called and completed visits can be moved, because the
     * examination sheet is sometimes handed in late; a completed visit simply
     * re-enters the queue and keeps its completion event in the log. The
     * queue number and visit date are kept, the visit re-enters review as
     * waiting, its stage slot is freed and the event log records the stage
     * it came from. A visit that is already in review is never moved again.
     */
    public function moveToReview(Visit $visit): JsonResponse
    {
        DB::transaction(function () use ($visit) {
            if ($visit->stage !== VisitStage::Consultation) {
                abort(409, 'Visit is already in the review stage.');
            }

            if ($visit->visit_date->toDateString() !== now()->toDateString()) {
                abort(409, 'Only today\'s visits can be moved to review.');
            }

            if (! in_array($visit->status, [VisitStatus::Waiting, VisitStatus::Called, VisitStatus::Completed], true)) {
                abort(409, 'Only a waiting, called or completed visit can be moved to review.');
            }

            $previousStage = $visit->stage;

            $moved = Visit::whereKey($visit->id)
                ->where('stage', VisitStage::Consultation->value)
                ->whereIn('status', [
                    VisitStatus::Waiting->value,
                    VisitStatus::Called->value,
                    VisitStatus::Completed->value,
                ])
                ->update([
                    'stage' => VisitStage::Review->value,
                    'status' => VisitStatus::Waiting->value,
                    'exam_number' => $this->nextExamNumber($visit->visit_date->toDateString()),
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);

            if ($moved !== 1) {
                abort(409, 'Visit can no longer be moved to review.');
            }

            $this->releaseStage($visit);

            $visit->events()->create([
                'event' => QueueEvent::EVENT_MOVED_TO_REVIEW,
                'stage' => $previousStage,
            ]);
        });

        return response()->json($this->present($visit->fresh()));
    }

    /**
     * Call one specific waiting visit of today instead of the next one.
     * The reception staff picks the patient manually; the stage slot rule
     * still applies, so the stage must be free before the visit is called.
     */
    public function callOne(Visit $visit): JsonResponse
    {
        $visit = DB::transaction(function () use ($visit) {
            if ($visit->visit_date->toDateString() !== now()->toDateString()) {
                abort(409, 'Only today\'s visits can be called.');
            }

            if ($visit->status !== VisitStatus::Waiting) {
                abort(409, 'Only a waiting visit can be called.');
            }

            $control = $this->controlFor($visit->stage);

            $this->callVisit($control, $visit);

            return $visit->fresh();
        });

        return response()->json($this->present($visit));
    }

    /**
     * Return a skipped visit of today back to its waiting queue. The queue
     * number and stage are kept, the visit becomes callable again, and the
     * record is never deleted.
     */
    public function restore(Visit $visit): JsonResponse
    {
        $visit = DB::transaction(function () use ($visit) {
            if ($visit->visit_date->toDateString() !== now()->toDateString()) {
                abort(409, 'Only today\'s visits can be restored.');
            }

            if ($visit->status !== VisitStatus::Skipped) {
                abort(409, 'Only a skipped visit can be restored.');
            }

            $restored = Visit::whereKey($visit->id)
                ->where('status', VisitStatus::Skipped->value)
                ->update(['status' => VisitStatus::Waiting->value, 'updated_at' => now()]);

            if ($restored !== 1) {
                abort(409, 'Visit can no longer be restored.');
            }

            $visit->events()->create([
                'event' => QueueEvent::EVENT_RESTORED,
                'stage' => $visit->stage,
            ]);

            return $visit->fresh();
        });

        return response()->json($this->present($visit));
    }

    /**
     * End the current work session: close every visit that was not served
     * yet (records and events are kept), free both stage slots and clear the
     * waiting-room display so no old number stays on screen. Numbering is
     * day based, so the next work day starts again from 001 without ever
     * reusing a number of a previous day.
     *
     * Runs in one transaction and locks the shared rows, so a concurrent
     * "call next" cannot claim a stage while the session is being closed,
     * and a repeated request closes nothing twice.
     */
    public function endWork(): JsonResponse
    {
        $result = DB::transaction(function () {
            $controls = QueueControl::whereIn('stage', [
                VisitStage::Consultation->value,
                VisitStage::Review->value,
            ])->orderBy('stage')->lockForUpdate()->get();

            $active = Visit::query()
                ->whereIn('status', [VisitStatus::Waiting->value, VisitStatus::Called->value])
                ->lockForUpdate()
                ->get();

            $closed = 0;

            foreach ($active as $visit) {
                // Conditional update: a concurrent request cannot close the
                // same visit twice, and the partial unique index on "called"
                // is freed for the next work day.
                $changed = Visit::whereKey($visit->id)
                    ->whereIn('status', [VisitStatus::Waiting->value, VisitStatus::Called->value])
                    ->update(['status' => VisitStatus::Skipped->value, 'updated_at' => now()]);

                if ($changed !== 1) {
                    continue;
                }

                $closed++;

                $visit->events()->create([
                    'event' => QueueEvent::EVENT_SESSION_CLOSED,
                    'stage' => $visit->stage,
                ]);
            }

            QueueControl::whereIn('stage', [
                VisitStage::Consultation->value,
                VisitStage::Review->value,
            ])->whereNotNull('current_visit_id')->update([
                'current_visit_id' => null,
                'updated_at' => now(),
            ]);

            DisplayState::query()->update([
                'current_visit_id' => null,
                'stage' => null,
                'updated_at' => now(),
            ]);

            $alreadyEnded = $closed === 0
                && $controls->every(fn (QueueControl $control) => $control->current_visit_id === null);

            return [
                'closed_visits' => $closed,
                'already_ended' => $alreadyEnded,
            ];
        });

        return response()->json([
            'closed_visits' => $result['closed_visits'],
            'already_ended' => $result['already_ended'],
        ]);
    }

    /**
     * Wipe today's queue data from the real state source the UI, the
     * waiting-room screen and the ticket printing read from: every ticket
     * (visit) of today, its whole call/event log, the current turn on both
     * stage boards and the public display state. The rows are deleted, not
     * hidden, so the daily counters restart from 001 for consultation
     * tickets and from F-001 for examination tickets. Visits of other days
     * are never touched.
     *
     * Deliberately independent from endWork(): the work session itself is
     * left exactly as it is (the paused flag is not changed, nothing is
     * marked as closed), so starting/ending work keeps its own behaviour.
     */
    public function clearToday(): JsonResponse
    {
        $deleted = DB::transaction(function () {
            // Hold both stage rows for the whole wipe so a concurrent
            // "call next" cannot claim a slot while data is disappearing.
            QueueControl::whereIn('stage', [
                VisitStage::Consultation->value,
                VisitStage::Review->value,
            ])->orderBy('stage')->lockForUpdate()->get();

            $visitIds = Visit::where('visit_date', now()->toDateString())->pluck('id');

            // Free the stage slots and the display first so no screen can
            // keep pointing at a visit that is about to disappear.
            QueueControl::whereIn('stage', [
                VisitStage::Consultation->value,
                VisitStage::Review->value,
            ])->whereNotNull('current_visit_id')->update([
                'current_visit_id' => null,
                'updated_at' => now(),
            ]);

            DisplayState::query()->update([
                'current_visit_id' => null,
                'stage' => null,
                'updated_at' => now(),
            ]);

            if ($visitIds->isEmpty()) {
                return 0;
            }

            QueueEvent::whereIn('visit_id', $visitIds)->delete();

            return Visit::whereIn('id', $visitIds)->delete();
        });

        return response()->json([
            'deleted_visits' => (int) $deleted,
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * Claim the stage slot for a visit and move it from waiting to called.
     * Uses conditional updates so concurrent calls cannot both succeed.
     */
    private function callVisit(QueueControl $control, Visit $visit): void
    {
        $claimed = QueueControl::whereKey($control->id)
            ->whereNull('current_visit_id')
            ->update(['current_visit_id' => $visit->id, 'updated_at' => now()]);

        if ($claimed !== 1) {
            abort(409, 'Another visit is already being called in this stage.');
        }

        $called = Visit::whereKey($visit->id)
            ->where('status', VisitStatus::Waiting->value)
            ->update(['status' => VisitStatus::Called->value, 'updated_at' => now()]);

        if ($called !== 1) {
            abort(409, 'Visit is no longer waiting.');
        }

        $visit->events()->create([
            'event' => QueueEvent::EVENT_CALLED,
            'stage' => $visit->stage,
        ]);

        // Approved display state: a singleton row tracking the last called
        // visit for the waiting-room screen, written inside the same
        // transaction so it never diverges from the queue.
        DisplayState::query()->updateOrCreate(
            [],
            ['current_visit_id' => $visit->id, 'stage' => $visit->stage]
        );
    }

    /**
     * Free the stage slot held by a visit after its fate is decided.
     */
    private function releaseStage(Visit $visit): void
    {
        QueueControl::where('stage', $visit->stage->value)
            ->where('current_visit_id', $visit->id)
            ->update(['current_visit_id' => null, 'updated_at' => now()]);
    }

    /**
     * The lowest-numbered waiting visit of today for a stage.
     */
    private function nextWaiting(VisitStage $stage): ?Visit
    {
        return Visit::query()
            ->where('stage', $stage->value)
            ->where('status', VisitStatus::Waiting->value)
            ->where('visit_date', now()->toDateString())
            ->orderBy('queue_number')
            ->first();
    }

    /**
     * The next free examination number (printed as F-001, F-002, ...) for a
     * day. Independent from the queue number and restartable: once the day's
     * visits are wiped, the first new examination ticket is F-001 again.
     */
    private function nextExamNumber(string $date): int
    {
        return ((int) Visit::where('visit_date', $date)
            ->whereNotNull('exam_number')
            ->max('exam_number')) + 1;
    }

    /**
     * Get or create the singleton control row for a stage.
     */
    private function controlFor(VisitStage $stage): QueueControl
    {
        try {
            return QueueControl::firstOrCreate(['stage' => $stage->value]);
        } catch (UniqueConstraintViolationException) {
            return QueueControl::where('stage', $stage->value)->firstOrFail();
        }
    }

    private function resolveStage(string $stage): VisitStage
    {
        return VisitStage::tryFrom($stage)
            ?? abort(422, 'Invalid stage. Allowed values: consultation, review.');
    }

    private function present(Visit $visit): array
    {
        return [
            'id' => $visit->id,
            'queue_number' => $visit->formattedQueueNumber(),
            'visit_date' => $visit->visit_date->toDateString(),
            'stage' => $visit->stage->value,
            'status' => $visit->status->value,
            'completed_at' => $visit->completed_at?->toIso8601String(),
        ];
    }
}
