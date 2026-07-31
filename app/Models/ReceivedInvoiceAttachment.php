<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivedInvoiceAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\ReceivedInvoiceAttachmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function receivedInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivedInvoice::class);
    }
}
