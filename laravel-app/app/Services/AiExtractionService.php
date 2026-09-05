<?php

namespace App\Services;

use App\Models\AiExtraction;
use App\Models\AuditLog;
use App\Models\Rfq;
use App\Models\RfqProposal;
use App\Services\AuditLogger;

class AiExtractionService
{
    /**
     * Official RFQ fields that may produce a conflict proposal.
     *
     * @var list<string>
     */
    private const COMPARABLE_FIELDS = [
        'commodity',
        'specification',
        'quantity',
        'unit',
        'incoterm',
        'destination',
    ];

    public function __construct(private MockAiExtractor $extractor) {}

    /**
     * Store a proposed extraction and any field conflicts. Does not write official RFQ fields.
     */
    public function extractForRfq(Rfq $rfq, string $text): AiExtraction
    {
        $extracted = $this->extractor->extract($text);

        if ($extracted === null) {
            abort(422, 'Unable to extract RFQ fields from the provided text.');
        }

        $extraction = $rfq->aiExtractions()->create([
            'commodity' => $extracted['commodity'],
            'specification' => $extracted['specification'],
            'quantity' => $extracted['quantity'],
            'unit' => $extracted['unit'],
            'incoterm' => $extracted['incoterm'],
            'destination' => $extracted['destination'],
            'confidence' => $extracted['confidence'],
            'source' => $extracted['source'],
            'status' => AiExtraction::STATUS_PENDING,
        ]);

        foreach (self::COMPARABLE_FIELDS as $field) {
            if ((string) $rfq->{$field} === (string) $extracted[$field]) {
                continue;
            }

            $proposal = new RfqProposal;
            $proposal->rfq()->associate($rfq);
            $proposal->aiExtraction()->associate($extraction);
            $proposal->field = $field;
            $proposal->current_value = (string) $rfq->{$field};
            $proposal->proposed_value = (string) $extracted[$field];
            $proposal->source = $extracted['source'];
            $proposal->confidence = $extracted['confidence'];
            $proposal->status = RfqProposal::STATUS_PENDING;
            $proposal->save();

            app(AuditLogger::class)->record(
                AuditLog::PROPOSAL_CREATED,
                $proposal,
                $rfq->company_id,
                before: [$field => $proposal->current_value],
                after: [$field => $proposal->proposed_value],
            );
        }

        return $extraction->load('proposals');
    }
}
