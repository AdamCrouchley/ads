<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivedEvent extends Model
{
    protected $fillable = [
        'event_id', 'event_type', 'schema_version', 'brand',
        'payload',
        'booking_reference', 'gclid', 'gbraid', 'wbraid',
        'value_amount', 'value_currency', 'event_occurred_at',
        'source_ip', 'received_at',
        'processing_status', 'processed_at', 'processing_error', 'processing_attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'event_occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'value_amount' => 'decimal:2',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_SKIPPED_NO_CLICKID = 'skipped_no_clickid';
    public const STATUS_FAILED = 'failed';
}
