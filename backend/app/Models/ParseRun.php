<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParseRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'chain_id',
        'city_id',
        'store_id',
        'status',
        'stores_count',
        'products_count',
        'offers_count',
        'error_message',
        'current_step',
        'heartbeat_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }
}
