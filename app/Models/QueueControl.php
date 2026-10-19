<?php

namespace App\Models;

use App\Enums\VisitStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueControl extends Model
{
    protected $fillable = [
        'stage',
        'is_paused',
        'current_visit_id',
    ];

    protected function casts(): array
    {
        return [
            'stage' => VisitStage::class,
            'is_paused' => 'boolean',
            'current_visit_id' => 'integer',
        ];
    }

    public function currentVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'current_visit_id');
    }
}
