<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Company extends Model
{
    /**
     * Classification flags are not mass-assignable. They are set by seeders /
     * trusted backend code only — never from client company_id or type fields.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_buyer' => 'boolean',
            'is_supplier' => 'boolean',
        ];
    }

    public function isBuyer(): bool
    {
        return (bool) $this->is_buyer;
    }

    public function isSupplier(): bool
    {
        return (bool) $this->is_supplier;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Rfq, $this>
     */
    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    /**
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_buyer' => $this->isBuyer(),
            'is_supplier' => $this->isSupplier(),
        ];
    }
}
