<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class PartyIdentityGrant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderParty, $this>
     */
    public function viewerParty(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderParty::class, 'viewer_party_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderParty, $this>
     */
    public function visibleParty(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderParty::class, 'visible_party_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
