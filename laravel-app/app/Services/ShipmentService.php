<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ShipmentService
{
    /**
     * Create a shipment from a confirmed PO (idempotent: one shipment per PO).
     *
     * @param  array{
     *     carrier?: string|null,
     *     tracking_number?: string|null,
     *     shipping_method?: string|null,
     *     notes?: string|null,
     *     origin_address?: array<string, mixed>|null,
     *     destination_address?: array<string, mixed>|null,
     * }  $input
     * @return array{shipment: Shipment, created: bool}
     */
    public function createFromConfirmedPurchaseOrder(PurchaseOrder $purchaseOrder, User $actor, array $input = []): array
    {
        return DB::transaction(function () use ($purchaseOrder, $actor, $input) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'rfq',
            ]);

            if ($locked->status !== PurchaseOrder::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Shipments can only be created from a confirmed purchase order.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->supplier_company_id) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only the supplier company may create a shipment for this purchase order.',
                ]);
            }

            $existing = Shipment::query()
                ->where('purchase_order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'shipment' => $existing->fresh([
                        'items',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                        'purchaseOrder',
                    ]),
                    'created' => false,
                ];
            }

            $shipment = new Shipment;
            $shipment->purchaseOrder()->associate($locked);
            $shipment->buyer_company_id = $locked->buyer_company_id;
            $shipment->supplier_company_id = $locked->supplier_company_id;
            $shipment->status = Shipment::STATUS_PENDING;
            $shipment->carrier = $this->nullableString($input['carrier'] ?? null);
            $shipment->tracking_number = $this->nullableString($input['tracking_number'] ?? null);
            $shipment->shipping_method = $this->nullableString($input['shipping_method'] ?? null);
            $shipment->notes = $this->nullableString($input['notes'] ?? null);
            $shipment->origin_address_snapshot = $this->buildOriginSnapshot($locked, $input['origin_address'] ?? null);
            $shipment->destination_address_snapshot = $this->buildDestinationSnapshot($locked, $input['destination_address'] ?? null);
            $shipment->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $shipment->save();

            $shipment->number = sprintf('SHP-%d-%06d', (int) $shipment->created_at->year, (int) $shipment->id);
            $shipment->save();

            foreach ($locked->items as $index => $item) {
                $this->snapshotItem($shipment, $item, $locked->currency, $index);
            }

            return [
                'shipment' => $shipment->fresh([
                    'items',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                    'purchaseOrder',
                ]),
                'created' => true,
            ];
        });
    }

    /**
     * @param  array{
     *     carrier?: string|null,
     *     tracking_number?: string|null,
     *     shipping_method?: string|null,
     *     notes?: string|null,
     * }  $input
     */
    public function updateOperational(Shipment $shipment, array $input): Shipment
    {
        return DB::transaction(function () use ($shipment, $input) {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOperationallyEditable()) {
                throw ValidationException::withMessages([
                    'shipment' => 'Shipment operational fields cannot be updated after shipping.',
                ]);
            }

            if (array_key_exists('carrier', $input)) {
                $locked->carrier = $this->nullableString($input['carrier']);
            }
            if (array_key_exists('tracking_number', $input)) {
                $locked->tracking_number = $this->nullableString($input['tracking_number']);
            }
            if (array_key_exists('shipping_method', $input)) {
                $locked->shipping_method = $this->nullableString($input['shipping_method']);
            }
            if (array_key_exists('notes', $input)) {
                $locked->notes = $this->nullableString($input['notes']);
            }

            $locked->save();

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'purchaseOrder',
            ]);
        });
    }

    public function markProcessing(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, Shipment::STATUS_PROCESSING);
    }

    public function markShipped(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, Shipment::STATUS_SHIPPED);
    }

    public function markDelivered(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, Shipment::STATUS_DELIVERED);
    }

    public function cancel(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, Shipment::STATUS_CANCELLED);
    }

    private function transition(Shipment $shipment, string $to): Shipment
    {
        return DB::transaction(function () use ($shipment, $to) {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'shipment' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'purchaseOrder',
            ]);
        });
    }

    private function snapshotItem(
        Shipment $shipment,
        PurchaseOrderItem $item,
        string $currency,
        int $sortOrder,
    ): void {
        $row = new ShipmentItem;
        $row->shipment()->associate($shipment);
        $row->purchase_order_item_id = $item->id;
        $row->product_id = $item->product_id;
        $row->description = $item->description;
        $row->quantity = $item->quantity;
        $row->unit = $item->unit;
        $row->unit_price = $item->unit_price;
        $row->line_total = $item->line_total;
        $row->currency = $currency;
        $row->product_snapshot = [
            'source' => 'confirmed_purchase_order_item',
            'captured_at' => now()->toISOString(),
            'purchase_order_item' => $item->toApiArray(),
            'product_snapshot' => $item->product_snapshot,
        ];
        $row->sort_order = $sortOrder;
        $row->save();
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function buildOriginSnapshot(PurchaseOrder $po, ?array $override): array
    {
        $profile = $po->supplierCompany?->supplierProfile;

        return $this->normalizeAddressSnapshot([
            'company_name' => $po->supplierCompany?->name,
            'display_name' => $profile?->display_name,
            'contact_name' => $override['contact_name'] ?? null,
            'phone' => $override['phone'] ?? $profile?->contact_phone,
            'address_line' => $override['address_line'] ?? null,
            'city' => $override['city'] ?? null,
            'state' => $override['state'] ?? ($override['region'] ?? null),
            'postal_code' => $override['postal_code'] ?? null,
            'country' => $override['country'] ?? null,
            'captured_at' => now()->toISOString(),
            'source' => 'confirmed_purchase_order_supplier',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function buildDestinationSnapshot(PurchaseOrder $po, ?array $override): array
    {
        return $this->normalizeAddressSnapshot([
            'company_name' => $po->buyerCompany?->name,
            'contact_name' => $override['contact_name'] ?? null,
            'phone' => $override['phone'] ?? null,
            'address_line' => $override['address_line'] ?? null,
            'city' => $override['city'] ?? $po->rfq?->destination,
            'state' => $override['state'] ?? ($override['region'] ?? null),
            'postal_code' => $override['postal_code'] ?? null,
            'country' => $override['country'] ?? null,
            'incoterm' => $po->rfq?->incoterm,
            'captured_at' => now()->toISOString(),
            'source' => 'confirmed_purchase_order_buyer',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeAddressSnapshot(array $data): array
    {
        return [
            'company_name' => $data['company_name'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'incoterm' => $data['incoterm'] ?? null,
            'captured_at' => $data['captured_at'] ?? now()->toISOString(),
            'source' => $data['source'] ?? null,
        ];
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
