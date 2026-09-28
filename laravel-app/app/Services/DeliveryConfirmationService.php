<?php

namespace App\Services;

use App\Models\DeliveryConfirmation;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryConfirmationService
{
    /**
     * Create a delivery confirmation for a delivered shipment (idempotent).
     *
     * @return array{confirmation: DeliveryConfirmation, created: bool}
     */
    public function createForDeliveredShipment(Shipment $shipment, User $actor, ?string $notes = null): array
    {
        return DB::transaction(function () use ($shipment, $actor, $notes) {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Shipment::STATUS_DELIVERED) {
                throw ValidationException::withMessages([
                    'shipment' => 'Delivery confirmation requires a delivered shipment.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->buyer_company_id) {
                throw ValidationException::withMessages([
                    'shipment' => 'Only the buyer company may confirm delivery for this shipment.',
                ]);
            }

            $existing = DeliveryConfirmation::query()
                ->where('shipment_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'confirmation' => $existing->fresh([
                        'shipment',
                        'purchaseOrder',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                        'confirmedBy',
                    ]),
                    'created' => false,
                ];
            }

            $confirmation = new DeliveryConfirmation;
            $confirmation->shipment()->associate($locked);
            $confirmation->purchase_order_id = $locked->purchase_order_id;
            $confirmation->buyer_company_id = $locked->buyer_company_id;
            $confirmation->supplier_company_id = $locked->supplier_company_id;
            $confirmation->confirmed_by_user_id = $actor->id;
            $confirmation->status = DeliveryConfirmation::STATUS_CONFIRMED;
            $confirmation->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $confirmation->confirmed_at = now();
            $confirmation->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $confirmation->save();

            $confirmation->number = sprintf('DEL-%d-%06d', (int) $confirmation->created_at->year, (int) $confirmation->id);
            $confirmation->save();

            return [
                'confirmation' => $confirmation->fresh([
                    'shipment',
                    'purchaseOrder',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                    'confirmedBy',
                ]),
                'created' => true,
            ];
        });
    }
}
