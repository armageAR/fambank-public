<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int     $id
 * @property int     $transaction_id
 * @property string  $disk
 * @property string  $path
 * @property string  $original_name
 * @property string  $mime_type
 * @property int     $size
 * @property int     $uploaded_by
 * @property string  $url            Accessor: URL pública del archivo
 * @property bool    $is_image       Accessor: true si el archivo es una imagen
 * @property string  $human_size     Accessor: "1.2 MB"
 */
class Attachment extends Model
{
    protected $fillable = [
        'transaction_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** El usuario que subió el archivo. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** URL firmada o pública según el disco configurado. */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::disk($this->disk)->url($this->path),
        );
    }

    /** True si el comprobante es una imagen (jpg, png, webp…). */
    protected function isImage(): Attribute
    {
        return Attribute::make(
            get: fn () => str_starts_with($this->mime_type, 'image/'),
        );
    }

    /** Tamaño legible: "245 KB", "1.2 MB". */
    protected function humanSize(): Attribute
    {
        return Attribute::make(
            get: function () {
                $bytes = $this->size;
                if ($bytes < 1024) {
                    return "{$bytes} B";
                }
                if ($bytes < 1_048_576) {
                    return round($bytes / 1024, 1) . ' KB';
                }
                return round($bytes / 1_048_576, 1) . ' MB';
            },
        );
    }
}
