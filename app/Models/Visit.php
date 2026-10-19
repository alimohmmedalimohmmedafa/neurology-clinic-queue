<?php

namespace App\Models;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected $fillable = [
        'queue_number',
        'exam_number',
        'visit_date',
        'stage',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_number' => 'integer',
            'exam_number' => 'integer',
            'visit_date' => 'date:Y-m-d',
            'stage' => VisitStage::class,
            'status' => VisitStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class);
    }

    public function queueControls(): HasMany
    {
        return $this->hasMany(QueueControl::class, 'current_visit_id');
    }

    public function displayStates(): HasMany
    {
        return $this->hasMany(DisplayState::class, 'current_visit_id');
    }

    public function formattedQueueNumber(): string
    {
        return str_pad((string) $this->queue_number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The daily examination number of a review visit, printed as F-001.
     */
    public function formattedExamNumber(): string
    {
        return 'F-'.str_pad((string) $this->exam_number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The number that goes on the printed ticket: review (examination)
     * tickets carry the F- series, every other ticket the queue number.
     */
    public function formattedTicketNumber(): string
    {
        if ($this->stage === VisitStage::Review && $this->exam_number !== null) {
            return $this->formattedExamNumber();
        }

        return $this->formattedQueueNumber();
    }
}
