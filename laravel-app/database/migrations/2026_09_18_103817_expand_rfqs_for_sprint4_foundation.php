<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('title')->nullable()->after('company_id');
            $table->text('description')->nullable()->after('title');
            $table->string('currency', 3)->nullable()->after('destination');
            $table->date('required_by_date')->nullable()->after('currency');
            $table->index('status');
            $table->index(['company_id', 'status']);
        });

        foreach (DB::table('rfqs')->whereNull('title')->get() as $rfq) {
            DB::table('rfqs')->where('id', $rfq->id)->update([
                'title' => $rfq->commodity ?: ('RFQ #'.$rfq->id),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'currency', 'required_by_date']);
        });
    }
};
