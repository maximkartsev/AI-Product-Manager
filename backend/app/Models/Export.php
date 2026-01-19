<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Export extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'video_id',
        'file_id',
        'format',
        'resolution',
        'quality',
        'bitrate',
        'fps',
        'codec',
        'status',
        'progress',
        'file_size',
        'download_url',
        'download_expires_at',
        'download_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'bitrate' => 'integer',
        'fps' => 'decimal:2',
        'progress' => 'integer',
        'file_size' => 'integer',
        'download_expires_at' => 'datetime',
        'download_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'video_id' => 'integer|required|exists:videos,id',
            'file_id' => 'integer|nullable|exists:files,id',
            'format' => 'string|nullable|max:20',
            'resolution' => 'string|nullable|max:20',
            'quality' => 'string|nullable|max:20',
            'bitrate' => 'integer|nullable|min:0',
            'fps' => 'numeric|nullable|min:0',
            'codec' => 'string|nullable|max:50',
            'status' => 'string|nullable|max:50',
            'progress' => 'integer|nullable|min:0|max:100',
            'file_size' => 'integer|nullable|min:0',
            'download_url' => 'string|nullable|max:500',
            'download_expires_at' => 'date|nullable',
            'download_count' => 'integer|nullable|min:0',
            'error_message' => 'string|nullable',
            'started_at' => 'date|nullable',
            'completed_at' => 'date|nullable',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
