<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class CreditNoteItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'line_total_snapshot' => 'decimal:2',
            'product_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /**
     * @return BelongsTo<RmaItem, $this>
     */
    public function rmaItem(): BelongsTo
    {
        return $this->belongsTo(RmaItem::class);
    }

    /**
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'credit_note_id' => $this->credit_note_id,
            'rma_item_id' => $this->rma_item_id,
            'invoice_item_id' => $this->invoice_item_id,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price_snapshot' => (string) $this->unit_price_snapshot,
            'line_total_snapshot' => (string) $this->line_total_snapshot,
            'currency' => $this->currency,
            'product_snapshot' => $this->product_snapshot,
            'sort_order' => $this->sort_order,
        ];
    }
}
