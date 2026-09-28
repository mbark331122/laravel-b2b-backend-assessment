<?php

namespace App\Services;

use App\Models\DeliveryConfirmation;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RmaService
{
    /**
     * @param  array{
     *     reason: string,
     *     notes?: string|null,
     *     items: list<array{shipment_item_id: int, quantity: int, reason?: string|null, notes?: string|null}>
     * }  $input
     */
    public function createForDeliveredShipment(Shipment $shipment, User $actor, array $input): Rma
    {
        $reason = (string) ($input['reason'] ?? '');
        if (! in_array($reason, Rma::REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => 'The selected return reason is invalid.',
            ]);
        }

        $notes = isset($input['notes']) ? $this->nullableString($input['notes']) : null;
        if ($reason === Rma::REASON_OTHER && ($notes === null || $notes === '')) {
            throw ValidationException::withMessages([
                'notes' => 'Notes are required when the return reason is other.',
            ]);
        }

        $items = $input['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one return item is required.',
            ]);
        }

        return DB::transaction(function () use ($shipment, $actor, $reason, $notes, $items) {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items', 'deliveryConfirmation']);

            if ($locked->status !== Shipment::STATUS_DELIVERED) {
                throw ValidationException::withMessages([
                    'shipment' => 'RMAs can only be created for a delivered shipment.',
                ]);
            }

            $confirmationExists = DeliveryConfirmation::query()
                ->where('shipment_id', $locked->id)
                ->exists();

            if (! $confirmationExists) {
                throw ValidationException::withMessages([
                    'shipment' => 'RMAs require a delivery confirmation for the shipment.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->buyer_company_id) {
                throw ValidationException::withMessages([
                    'shipment' => 'Only the buyer company may create an RMA for this shipment.',
                ]);
            }

            $active = Rma::query()
                ->where('shipment_id', $locked->id)
                ->whereIn('status', Rma::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw ValidationException::withMessages([
                    'shipment' => 'An active RMA already exists for this shipment.',
                ]);
            }

            $rma = new Rma;
            $rma->shipment()->associate($locked);
            $rma->purchase_order_id = $locked->purchase_order_id;
            $rma->buyer_company_id = $locked->buyer_company_id;
            $rma->supplier_company_id = $locked->supplier_company_id;
            $rma->requested_by_user_id = $actor->id;
            $rma->status = Rma::STATUS_REQUESTED;
            $rma->reason = $reason;
            $rma->notes = $notes;
            $rma->requested_at = now();
            $rma->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $rma->save();

            $rma->number = sprintf('RMA-%d-%06d', (int) $rma->created_at->year, (int) $rma->id);
            $rma->active_lock = $locked->id;
            $rma->save();

            $shipmentItems = $locked->items->keyBy('id');
            $seen = [];

            foreach (array_values($items) as $index => $row) {
                $shipmentItemId = (int) ($row['shipment_item_id'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);

                if (isset($seen[$shipmentItemId])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.shipment_item_id" => 'Duplicate shipment item in RMA request.',
                    ]);
                }
                $seen[$shipmentItemId] = true;

                /** @var ShipmentItem|null $shipmentItem */
                $shipmentItem = $shipmentItems->get($shipmentItemId);
                if (! $shipmentItem) {
                    throw ValidationException::withMessages([
                        "items.{$index}.shipment_item_id" => 'Shipment item does not belong to this shipment.',
                    ]);
                }

                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Return quantity must be at least 1.',
                    ]);
                }

                if ($quantity > (int) $shipmentItem->quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Return quantity cannot exceed the shipped quantity.',
                    ]);
                }

                $itemReason = isset($row['reason']) ? $this->nullableString($row['reason']) : null;
                if ($itemReason !== null && ! in_array($itemReason, Rma::REASONS, true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.reason" => 'The selected item return reason is invalid.',
                    ]);
                }

                $item = new RmaItem;
                $item->rma()->associate($rma);
                $item->shipment_item_id = $shipmentItem->id;
                $item->purchase_order_item_id = $shipmentItem->purchase_order_item_id;
                $item->product_id = $shipmentItem->product_id;
                $item->description = $shipmentItem->description;
                $item->quantity = $quantity;
                $item->unit = $shipmentItem->unit;
                $item->reason = $itemReason;
                $item->notes = isset($row['notes']) ? $this->nullableString($row['notes']) : null;
                $item->product_snapshot = [
                    'source' => 'shipment_item',
                    'captured_at' => now()->toISOString(),
                    'shipment_item' => $shipmentItem->toApiArray(),
                    'product_snapshot' => $shipmentItem->product_snapshot,
                ];
                $item->sort_order = $index;
                $item->save();
            }

            return $rma->fresh([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ]);
        });
    }

    public function cancel(Rma $rma, User $actor): Rma
    {
        return $this->transition($rma, Rma::STATUS_CANCELLED, $actor);
    }

    public function approve(Rma $rma, User $actor): Rma
    {
        return $this->transition($rma, Rma::STATUS_APPROVED, $actor);
    }

    public function reject(Rma $rma, User $actor, string $rejectionReason): Rma
    {
        $rejectionReason = trim($rejectionReason);
        if ($rejectionReason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($rma, $actor, $rejectionReason) {
            /** @var Rma $locked */
            $locked = Rma::query()->whereKey($rma->id)->lockForUpdate()->firstOrFail();
            $locked->rejection_reason = $rejectionReason;

            try {
                $locked->transitionTo(Rma::STATUS_REJECTED, $actor);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'rma' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ]);
        });
    }

    public function markReceived(Rma $rma, User $actor): Rma
    {
        return $this->transition($rma, Rma::STATUS_RECEIVED, $actor);
    }

    public function close(Rma $rma, User $actor): Rma
    {
        return $this->transition($rma, Rma::STATUS_CLOSED, $actor);
    }

    private function transition(Rma $rma, string $to, User $actor): Rma
    {
        return DB::transaction(function () use ($rma, $to, $actor) {
            /** @var Rma $locked */
            $locked = Rma::query()->whereKey($rma->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to, $actor);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'rma' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ]);
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
