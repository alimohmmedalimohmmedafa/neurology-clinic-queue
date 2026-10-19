<?php

namespace App\Models;

use App\Enums\VisitStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEvent extends Model
{
    public const EVENT_CREATED = 'created';

    public const EVENT_CALLED = 'called';

    public const EVENT_SKIPPED = 'skipped';

    public const EVENT_COMPLETED = 'completed';

    public const EVENT_MOVED_TO_REVIEW = 'moved_to_review';

    public const EVENT_RECALLED = 'recalled';

    public const EVENT_DISPLAYED = 'displayed';

    public const EVENT_SESSION_CLOSED = 'session_closed';

    public const EVENT_RESTORED = 'restored';

    public const UPDATED_AT = null;

    protected $fillable = [
        'visit_id',
        'event',
        'stage',
    ];

    protected function casts(): array
    {
        return [
            'visit_id' => 'integer',
            'stage' => VisitStage::class,
            'created_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
