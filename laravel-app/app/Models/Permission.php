<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Permission extends Model
{
    public const RFQ_READ = 'rfq.read';

    public const RFQ_CREATE = 'rfq.create';

    public const RFQ_UPDATE = 'rfq.update';

    public const RFQ_APPROVE = 'rfq.approve';

    public const BANK_READ = 'bank.read';

    public const BANK_CHANGE_REQUEST = 'bank.change_request';

    public const BANK_APPROVE = 'bank.approve';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::RFQ_READ,
            self::RFQ_CREATE,
            self::RFQ_UPDATE,
            self::RFQ_APPROVE,
            self::BANK_READ,
            self::BANK_CHANGE_REQUEST,
            self::BANK_APPROVE,
        ];
    }

    /**
     * Permissions granted to company users. Approval stays with admin.
     *
     * @return list<string>
     */
    public static function companyUserNames(): array
    {
        return [
            self::RFQ_READ,
            self::RFQ_CREATE,
            self::RFQ_UPDATE,
            self::BANK_READ,
            self::BANK_CHANGE_REQUEST,
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
