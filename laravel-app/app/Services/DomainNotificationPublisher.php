<?php

namespace App\Services;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\CreditNote;
use App\Models\DeliveryConfirmation;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\ReturnShipment;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maps committed domain events to in-app notifications.
 * Titles/bodies intentionally omit party identities (Assessment 2 safe).
 */
class DomainNotificationPublisher
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function rfqSubmitted(Rfq $rfq): void
    {
        $users = $this->notifications->activeUsersForCompany((int) $rfq->company_id);
        $this->notifications->notifyCompanyUsers(
            (int) $rfq->company_id,
            $users,
            Notification::TYPE_RFQ_SUBMITTED,
            'RFQ submitted',
            'An RFQ was submitted and is ready for supplier distribution.',
            $rfq,
            ['rfq_id' => $rfq->id],
        );
    }

    public function rfqDistributed(Rfq $rfq, RfqDistribution $distribution): void
    {
        $supplierCompanyId = (int) $distribution->supplier_company_id;
        $users = $this->notifications->activeUsersForCompany($supplierCompanyId);
        $this->notifications->notifyCompanyUsers(
            $supplierCompanyId,
            $users,
            Notification::TYPE_RFQ_DISTRIBUTED,
            'New RFQ distribution',
            'An RFQ was distributed to your company for quotation.',
            $distribution,
            ['rfq_id' => $rfq->id, 'distribution_id' => $distribution->id],
        );
    }

    public function purchaseOrderSubmitted(PurchaseOrder $po): void
    {
        $this->notifyCompany(
            (int) $po->supplier_company_id,
            Notification::TYPE_PURCHASE_ORDER_SUBMITTED,
            'Purchase order awaiting confirmation',
            'A purchase order was submitted and awaits supplier confirmation.',
            $po,
            ['purchase_order_id' => $po->id, 'number' => $po->number],
        );
    }

    public function purchaseOrderConfirmed(PurchaseOrder $po): void
    {
        $this->notifyCompany(
            (int) $po->buyer_company_id,
            Notification::TYPE_PURCHASE_ORDER_CONFIRMED,
            'Purchase order confirmed',
            'A purchase order was confirmed by the supplier.',
            $po,
            ['purchase_order_id' => $po->id, 'number' => $po->number],
        );
    }

    public function purchaseOrderRejected(PurchaseOrder $po): void
    {
        $this->notifyCompany(
            (int) $po->buyer_company_id,
            Notification::TYPE_PURCHASE_ORDER_REJECTED,
            'Purchase order rejected',
            'A purchase order was rejected by the supplier.',
            $po,
            ['purchase_order_id' => $po->id, 'number' => $po->number],
        );
    }

    public function purchaseOrderCompleted(PurchaseOrder $po): void
    {
        foreach ([(int) $po->buyer_company_id, (int) $po->supplier_company_id] as $companyId) {
            $this->notifyCompany(
                $companyId,
                Notification::TYPE_PURCHASE_ORDER_COMPLETED,
                'Purchase order completed',
                'A purchase order was marked completed.',
                $po,
                ['purchase_order_id' => $po->id, 'number' => $po->number],
                dedupeSuffix: (string) $companyId,
            );
        }
    }

    public function paymentCreated(Payment $payment): void
    {
        $this->notifyPaymentParties($payment, Notification::TYPE_PAYMENT_CREATED, 'Payment created', 'A payment record was created.');
    }

    public function paymentMarkedPaid(Payment $payment): void
    {
        $this->notifyPaymentParties($payment, Notification::TYPE_PAYMENT_MARKED_PAID, 'Payment marked paid', 'A payment was marked as paid.');
    }

    public function paymentMarkedFailed(Payment $payment): void
    {
        $this->notifyPaymentParties($payment, Notification::TYPE_PAYMENT_MARKED_FAILED, 'Payment marked failed', 'A payment was marked as failed.');
    }

    public function paymentCancelled(Payment $payment): void
    {
        $this->notifyPaymentParties($payment, Notification::TYPE_PAYMENT_CANCELLED, 'Payment cancelled', 'A payment was cancelled.');
    }

    public function shipmentCreated(Shipment $shipment): void
    {
        $this->notifyShipmentParties($shipment, Notification::TYPE_SHIPMENT_CREATED, 'Shipment created', 'A shipment was created for a purchase order.');
    }

    public function shipmentShipped(Shipment $shipment): void
    {
        $this->notifyShipmentParties($shipment, Notification::TYPE_SHIPMENT_SHIPPED, 'Shipment shipped', 'A shipment was marked as shipped.');
    }

    public function shipmentDelivered(Shipment $shipment): void
    {
        $this->notifyShipmentParties($shipment, Notification::TYPE_SHIPMENT_DELIVERED, 'Shipment delivered', 'A shipment was marked as delivered.');
    }

    public function shipmentCancelled(Shipment $shipment): void
    {
        $this->notifyShipmentParties($shipment, Notification::TYPE_SHIPMENT_CANCELLED, 'Shipment cancelled', 'A shipment was cancelled.');
    }

    public function deliveryConfirmationCreated(DeliveryConfirmation $confirmation): void
    {
        $this->notifyCompany(
            (int) $confirmation->supplier_company_id,
            Notification::TYPE_DELIVERY_CONFIRMATION_CREATED,
            'Delivery confirmed',
            'Buyer delivery confirmation was recorded for a shipment.',
            $confirmation,
            ['delivery_confirmation_id' => $confirmation->id, 'shipment_id' => $confirmation->shipment_id],
        );
    }

    public function rmaCreated(Rma $rma): void
    {
        $this->notifyCompany(
            (int) $rma->supplier_company_id,
            Notification::TYPE_RMA_CREATED,
            'RMA requested',
            'A return merchandise authorization was requested.',
            $rma,
            ['rma_id' => $rma->id],
        );
    }

    public function rmaApproved(Rma $rma): void
    {
        $this->notifyCompany(
            (int) $rma->buyer_company_id,
            Notification::TYPE_RMA_APPROVED,
            'RMA approved',
            'An RMA was approved by the supplier.',
            $rma,
            ['rma_id' => $rma->id],
        );
    }

    public function rmaRejected(Rma $rma): void
    {
        $this->notifyCompany(
            (int) $rma->buyer_company_id,
            Notification::TYPE_RMA_REJECTED,
            'RMA rejected',
            'An RMA was rejected by the supplier.',
            $rma,
            ['rma_id' => $rma->id],
        );
    }

    public function rmaReceived(Rma $rma): void
    {
        $this->notifyCompany(
            (int) $rma->buyer_company_id,
            Notification::TYPE_RMA_RECEIVED,
            'RMA received',
            'Returned goods were marked received for an RMA.',
            $rma,
            ['rma_id' => $rma->id],
        );
    }

    public function rmaClosed(Rma $rma): void
    {
        foreach ([(int) $rma->buyer_company_id, (int) $rma->supplier_company_id] as $companyId) {
            $this->notifyCompany(
                $companyId,
                Notification::TYPE_RMA_CLOSED,
                'RMA closed',
                'An RMA was closed.',
                $rma,
                ['rma_id' => $rma->id],
                dedupeSuffix: (string) $companyId,
            );
        }
    }

    public function returnShipmentCreated(ReturnShipment $shipment): void
    {
        $this->notifyCompany(
            (int) $shipment->supplier_company_id,
            Notification::TYPE_RETURN_SHIPMENT_CREATED,
            'Return shipment created',
            'A return shipment was created for an approved RMA.',
            $shipment,
            ['return_shipment_id' => $shipment->id],
        );
    }

    public function returnShipmentShipped(ReturnShipment $shipment): void
    {
        $this->notifyCompany(
            (int) $shipment->supplier_company_id,
            Notification::TYPE_RETURN_SHIPMENT_SHIPPED,
            'Return shipment shipped',
            'A return shipment was marked as shipped.',
            $shipment,
            ['return_shipment_id' => $shipment->id],
        );
    }

    public function returnShipmentDelivered(ReturnShipment $shipment): void
    {
        $this->notifyCompany(
            (int) $shipment->buyer_company_id,
            Notification::TYPE_RETURN_SHIPMENT_DELIVERED,
            'Return shipment delivered',
            'A return shipment was marked as delivered.',
            $shipment,
            ['return_shipment_id' => $shipment->id],
        );
    }

    public function returnShipmentCancelled(ReturnShipment $shipment): void
    {
        foreach ([(int) $shipment->buyer_company_id, (int) $shipment->supplier_company_id] as $companyId) {
            $this->notifyCompany(
                $companyId,
                Notification::TYPE_RETURN_SHIPMENT_CANCELLED,
                'Return shipment cancelled',
                'A return shipment was cancelled.',
                $shipment,
                ['return_shipment_id' => $shipment->id],
                dedupeSuffix: (string) $companyId,
            );
        }
    }

    public function creditNoteIssued(CreditNote $note): void
    {
        $this->notifyCompany(
            (int) $note->buyer_company_id,
            Notification::TYPE_CREDIT_NOTE_ISSUED,
            'Credit note issued',
            'A credit note was issued.',
            $note,
            ['credit_note_id' => $note->id],
        );
    }

    public function creditNoteCancelled(CreditNote $note): void
    {
        $this->notifyCompany(
            (int) $note->buyer_company_id,
            Notification::TYPE_CREDIT_NOTE_CANCELLED,
            'Credit note cancelled',
            'A credit note was cancelled.',
            $note,
            ['credit_note_id' => $note->id],
        );
    }

    public function creditNoteVoided(CreditNote $note): void
    {
        $this->notifyCompany(
            (int) $note->buyer_company_id,
            Notification::TYPE_CREDIT_NOTE_VOIDED,
            'Credit note voided',
            'A credit note was voided.',
            $note,
            ['credit_note_id' => $note->id],
        );
    }

    public function refundProcessed(Refund $refund): void
    {
        $this->notifyCompany(
            (int) $refund->buyer_company_id,
            Notification::TYPE_REFUND_PROCESSED,
            'Refund processed',
            'A refund was processed.',
            $refund,
            ['refund_id' => $refund->id],
        );
    }

    public function refundFailed(Refund $refund): void
    {
        $this->notifyCompany(
            (int) $refund->buyer_company_id,
            Notification::TYPE_REFUND_FAILED,
            'Refund failed',
            'A refund was marked failed.',
            $refund,
            ['refund_id' => $refund->id],
        );
    }

    public function refundCancelled(Refund $refund): void
    {
        $this->notifyCompany(
            (int) $refund->buyer_company_id,
            Notification::TYPE_REFUND_CANCELLED,
            'Refund cancelled',
            'A refund was cancelled.',
            $refund,
            ['refund_id' => $refund->id],
        );
    }

    public function approvalRequestCreated(ApprovalRequest $request): void
    {
        $dispatch = function () use ($request): void {
            $fresh = $request->fresh(['decisions', 'requester']);
            if ($fresh === null) {
                return;
            }

            $this->notifyApprovalStepRequired($fresh);

            // Requester acknowledgment (same company).
            if ($fresh->requester_id) {
                $this->notifications->notifyCompanyUsers(
                    (int) $fresh->company_id,
                    [(int) $fresh->requester_id],
                    Notification::TYPE_APPROVAL_REQUEST_CREATED,
                    'Approval request created',
                    'An approval request was created and is awaiting decision.',
                    $fresh,
                    ['approval_request_id' => $fresh->id, 'approval_type' => $fresh->approval_type],
                );
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function approvalRequestApproved(ApprovalRequest $request): void
    {
        $dispatch = function () use ($request): void {
            $fresh = $request->fresh();
            if ($fresh === null || $fresh->requester_id === null) {
                return;
            }

            $this->notifications->notifyCompanyUsers(
                (int) $fresh->company_id,
                [(int) $fresh->requester_id],
                Notification::TYPE_APPROVAL_REQUEST_APPROVED,
                'Approval request approved',
                'Your approval request was fully approved.',
                $fresh,
                ['approval_request_id' => $fresh->id],
            );
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function approvalRequestRejected(ApprovalRequest $request): void
    {
        $dispatch = function () use ($request): void {
            $fresh = $request->fresh();
            if ($fresh === null || $fresh->requester_id === null) {
                return;
            }

            $this->notifications->notifyCompanyUsers(
                (int) $fresh->company_id,
                [(int) $fresh->requester_id],
                Notification::TYPE_APPROVAL_REQUEST_REJECTED,
                'Approval request rejected',
                'Your approval request was rejected.',
                $fresh,
                ['approval_request_id' => $fresh->id],
            );
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function approvalRequestCancelled(ApprovalRequest $request): void
    {
        $dispatch = function () use ($request): void {
            $fresh = $request->fresh(['decisions']);
            if ($fresh === null) {
                return;
            }

            $decision = $fresh->decisions->firstWhere('step_order', $fresh->current_step_order);
            $role = $decision?->approver_role;
            $approvers = $role
                ? $this->notifications->activeUsersForCompany((int) $fresh->company_id, $role)
                : collect();

            $this->notifications->notifyCompanyUsers(
                (int) $fresh->company_id,
                $approvers,
                Notification::TYPE_APPROVAL_REQUEST_CANCELLED,
                'Approval request cancelled',
                'An approval request was cancelled.',
                $fresh,
                ['approval_request_id' => $fresh->id],
            );
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function approvalStepAdvanced(ApprovalRequest $request): void
    {
        $dispatch = function () use ($request): void {
            $fresh = $request->fresh(['decisions']);
            if ($fresh === null || ! $fresh->isPending()) {
                return;
            }

            $this->notifyApprovalStepRequired($fresh);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    private function notifyApprovalStepRequired(ApprovalRequest $request): void
    {
        /** @var ApprovalDecision|null $decision */
        $decision = $request->decisions->firstWhere('step_order', $request->current_step_order);
        if ($decision === null || ! $decision->isPending()) {
            return;
        }

        $approvers = $this->notifications
            ->activeUsersForCompany((int) $request->company_id, $decision->approver_role)
            ->reject(fn (User $user) => $user->id === $request->requester_id);

        $this->notifications->notifyCompanyUsers(
            (int) $request->company_id,
            $approvers,
            Notification::TYPE_APPROVAL_STEP_REQUIRED,
            'Approval decision required',
            'An approval request requires your decision.',
            $request,
            [
                'approval_request_id' => $request->id,
                'step_order' => $decision->step_order,
            ],
            dedupeSuffix: 'step-'.$decision->step_order,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function notifyCompany(
        int $companyId,
        string $type,
        string $title,
        string $body,
        $related,
        array $metadata = [],
        ?string $dedupeSuffix = null,
    ): void {
        $users = $this->notifications->activeUsersForCompany($companyId);
        $this->notifications->notifyCompanyUsers(
            $companyId,
            $users,
            $type,
            $title,
            $body,
            $related,
            $metadata,
            $dedupeSuffix,
        );
    }

    private function notifyPaymentParties(Payment $payment, string $type, string $title, string $body): void
    {
        foreach ([(int) $payment->buyer_company_id, (int) $payment->supplier_company_id] as $companyId) {
            $this->notifyCompany(
                $companyId,
                $type,
                $title,
                $body,
                $payment,
                ['payment_id' => $payment->id],
                dedupeSuffix: (string) $companyId,
            );
        }
    }

    private function notifyShipmentParties(Shipment $shipment, string $type, string $title, string $body): void
    {
        foreach ([(int) $shipment->buyer_company_id, (int) $shipment->supplier_company_id] as $companyId) {
            $this->notifyCompany(
                $companyId,
                $type,
                $title,
                $body,
                $shipment,
                ['shipment_id' => $shipment->id],
                dedupeSuffix: (string) $companyId,
            );
        }
    }
}
