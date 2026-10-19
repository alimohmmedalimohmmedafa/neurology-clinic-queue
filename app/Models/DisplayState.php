<?php

namespace App\Models;

use App\Enums\VisitStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplayState extends Model
{
    protected $table = 'display_state';

    protected $fillable = [
        'current_visit_id',
        'stage',
    ];

    protected function casts(): array
    {
        return [
            'current_visit_id' => 'integer',
            'stage' => VisitStage::class,
        ];
    }

    public function currentVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'current_visit_id');
    }
}
