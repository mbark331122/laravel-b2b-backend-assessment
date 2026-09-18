<?php

namespace App\Services;

use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\ReturnShipment;
use App\Models\ReturnShipmentItem;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReturnShipmentService
{
    /**
     * @param  array{
     *     carrier?: string|null,
     *     tracking_number?: string|null,
     *     shipping_method?: string|null,
     *     origin_address?: array<string, mixed>|null,
     *     destination_address?: array<string, mixed>|null,
     *     items: list<array{rma_item_id: int, quantity: int}>
     * }  $input
     * @return array{return_shipment: ReturnShipment, created: bool}
     */
    public function createForApprovedRma(Rma $rma, User $actor, array $input): array
    {
        $items = $input['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one return shipment item is required.',
            ]);
        }

        return DB::transaction(function () use ($rma, $actor, $input, $items) {
            /** @var Rma $locked */
            $locked = Rma::query()->whereKey($rma->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing([
                'items',
                'shipment',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'purchaseOrder.rfq',
            ]);

            if ($locked->status !== Rma::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'rma' => 'Return shipments can only be created for an approved RMA.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->buyer_company_id) {
                throw ValidationException::withMessages([
                    'rma' => 'Only the buyer company may create a return shipment for this RMA.',
                ]);
            }

            $existingActive = ReturnShipment::query()
                ->where('rma_id', $locked->id)
                ->whereIn('status', ReturnShipment::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existingActive) {
                return [
                    'return_shipment' => $existingActive->fresh([
                        'items',
                        'rma',
                        'shipment',
                        'purchaseOrder',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                    ]),
                    'created' => false,
                ];
            }

            $returnShipment = new ReturnShipment;
            $returnShipment->rma()->associate($locked);
            $returnShipment->shipment_id = $locked->shipment_id;
            $returnShipment->purchase_order_id = $locked->purchase_order_id;
            $returnShipment->buyer_company_id = $locked->buyer_company_id;
            $returnShipment->supplier_company_id = $locked->supplier_company_id;
            $returnShipment->status = ReturnShipment::STATUS_PENDING;
            $returnShipment->carrier = $this->nullableString($input['carrier'] ?? null);
            $returnShipment->tracking_number = $this->nullableString($input['tracking_number'] ?? null);
            $returnShipment->shipping_method = $this->nullableString($input['shipping_method'] ?? null);
            $returnShipment->origin_address_snapshot = $this->buildOriginSnapshot($locked, $input['origin_address'] ?? null);
            $returnShipment->destination_address_snapshot = $this->buildDestinationSnapshot($locked, $input['destination_address'] ?? null);
            $returnShipment->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $returnShipment->save();

            $returnShipment->number = sprintf('RMA-RET-%d-%06d', (int) $returnShipment->created_at->year, (int) $returnShipment->id);
            $returnShipment->active_lock = $locked->id;
            $returnShipment->save();

            $rmaItems = $locked->items->keyBy('id');
            $seen = [];

            foreach (array_values($items) as $index => $row) {
                $rmaItemId = (int) ($row['rma_item_id'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);

                if (isset($seen[$rmaItemId])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.rma_item_id" => 'Duplicate RMA item in return shipment.',
                    ]);
                }
                $seen[$rmaItemId] = true;

                /** @var RmaItem|null $rmaItem */
                $rmaItem = $rmaItems->get($rmaItemId);
                if (! $rmaItem) {
                    throw ValidationException::withMessages([
                        "items.{$index}.rma_item_id" => 'RMA item does not belong to this RMA.',
                    ]);
                }

                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Return shipment quantity must be at least 1.',
                    ]);
                }

                if ($quantity > (int) $rmaItem->quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Return shipment quantity cannot exceed the RMA item quantity.',
                    ]);
                }

                /** @var ShipmentItem|null $shipmentItem */
                $shipmentItem = ShipmentItem::query()->find($rmaItem->shipment_item_id);
                if (! $shipmentItem || (int) $shipmentItem->shipment_id !== (int) $locked->shipment_id) {
                    throw ValidationException::withMessages([
                        "items.{$index}.rma_item_id" => 'RMA item shipment line does not belong to the original shipment.',
                    ]);
                }

                $unitPrice = (string) $shipmentItem->unit_price;
                $lineTotal = bcmul($unitPrice, (string) $quantity, 2);

                $item = new ReturnShipmentItem;
                $item->returnShipment()->associate($returnShipment);
                $item->rma_item_id = $rmaItem->id;
                $item->shipment_item_id = $shipmentItem->id;
                $item->product_id = $shipmentItem->product_id;
                $item->description = $rmaItem->description;
                $item->quantity = $quantity;
                $item->unit = $rmaItem->unit;
                $item->unit_price_snapshot = $unitPrice;
                $item->line_total_snapshot = $lineTotal;
                $item->currency = $shipmentItem->currency;
                $item->product_snapshot = [
                    'source' => 'rma_item',
                    'captured_at' => now()->toISOString(),
                    'rma_item' => $rmaItem->toApiArray(),
                    'shipment_item' => $shipmentItem->toApiArray(),
                    'product_snapshot' => $rmaItem->product_snapshot,
                ];
                $item->sort_order = $index;
                $item->save();
            }

            return [
                'return_shipment' => $returnShipment->fresh([
                    'items',
                    'rma',
                    'shipment',
                    'purchaseOrder',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
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
     *     origin_address?: array<string, mixed>|null,
     *     destination_address?: array<string, mixed>|null,
     * }  $input
     */
    public function updateOperational(ReturnShipment $returnShipment, array $input): ReturnShipment
    {
        return DB::transaction(function () use ($returnShipment, $input) {
            /** @var ReturnShipment $locked */
            $locked = ReturnShipment::query()->whereKey($returnShipment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['rma.buyerCompany', 'rma.supplierCompany.supplierProfile', 'rma.purchaseOrder.rfq', 'rma.shipment']);

            if (! $locked->isOperationallyEditable()) {
                throw ValidationException::withMessages([
                    'return_shipment' => 'Return shipment can only be updated while pending.',
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
            if (array_key_exists('origin_address', $input) && is_array($input['origin_address'])) {
                $locked->origin_address_snapshot = $this->buildOriginSnapshot($locked->rma, $input['origin_address']);
            }
            if (array_key_exists('destination_address', $input) && is_array($input['destination_address'])) {
                $locked->destination_address_snapshot = $this->buildDestinationSnapshot($locked->rma, $input['destination_address']);
            }

            $locked->save();

            return $locked->fresh([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function ship(ReturnShipment $returnShipment): ReturnShipment
    {
        return $this->transition($returnShipment, ReturnShipment::STATUS_SHIPPED);
    }

    public function deliver(ReturnShipment $returnShipment): ReturnShipment
    {
        return $this->transition($returnShipment, ReturnShipment::STATUS_DELIVERED);
    }

    public function cancel(ReturnShipment $returnShipment): ReturnShipment
    {
        return $this->transition($returnShipment, ReturnShipment::STATUS_CANCELLED);
    }

    private function transition(ReturnShipment $returnShipment, string $to): ReturnShipment
    {
        return DB::transaction(function () use ($returnShipment, $to) {
            /** @var ReturnShipment $locked */
            $locked = ReturnShipment::query()->whereKey($returnShipment->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'return_shipment' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    /**
     * Buyer origin for returning goods.
     *
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function buildOriginSnapshot(Rma $rma, ?array $override): array
    {
        $shipment = $rma->shipment;
        $base = is_array($shipment?->destination_address_snapshot) ? $shipment->destination_address_snapshot : [];

        return $this->normalizeAddressSnapshot([
            'company_name' => $override['company_name'] ?? ($base['company_name'] ?? $rma->buyerCompany?->name),
            'contact_name' => $override['contact_name'] ?? ($base['contact_name'] ?? null),
            'phone' => $override['phone'] ?? ($base['phone'] ?? null),
            'address_line' => $override['address_line'] ?? ($base['address_line'] ?? null),
            'city' => $override['city'] ?? ($base['city'] ?? $rma->purchaseOrder?->rfq?->destination),
            'state' => $override['state'] ?? ($override['region'] ?? ($base['state'] ?? null)),
            'postal_code' => $override['postal_code'] ?? ($base['postal_code'] ?? null),
            'country' => $override['country'] ?? ($base['country'] ?? null),
            'captured_at' => now()->toISOString(),
            'source' => 'rma_return_buyer_origin',
        ]);
    }

    /**
     * Supplier destination for receiving returned goods.
     *
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function buildDestinationSnapshot(Rma $rma, ?array $override): array
    {
        $shipment = $rma->shipment;
        $base = is_array($shipment?->origin_address_snapshot) ? $shipment->origin_address_snapshot : [];
        $profile = $rma->supplierCompany?->supplierProfile;

        return $this->normalizeAddressSnapshot([
            'company_name' => $override['company_name'] ?? ($base['company_name'] ?? $rma->supplierCompany?->name),
            'display_name' => $override['display_name'] ?? ($base['display_name'] ?? $profile?->display_name),
            'contact_name' => $override['contact_name'] ?? ($base['contact_name'] ?? null),
            'phone' => $override['phone'] ?? ($base['phone'] ?? $profile?->contact_phone),
            'address_line' => $override['address_line'] ?? ($base['address_line'] ?? null),
            'city' => $override['city'] ?? ($base['city'] ?? null),
            'state' => $override['state'] ?? ($override['region'] ?? ($base['state'] ?? null)),
            'postal_code' => $override['postal_code'] ?? ($base['postal_code'] ?? null),
            'country' => $override['country'] ?? ($base['country'] ?? null),
            'captured_at' => now()->toISOString(),
            'source' => 'rma_return_supplier_destination',
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
