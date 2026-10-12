<?php

namespace App\Console\Commands;

use App\Models\ProjectOpportunity;
use Illuminate\Console\Command;

/**
 * رقابة إدارية على الفرص المنشورة: تعليق أو سحب أو إعادة نشر. التعليق والسحب يخفيان الفرصة
 * فورًا عن الطلاب (بما في ذلك الروابط المباشرة)، ولا يُلغيان باعتماد المؤسسة لنسخة جديدة.
 */
class ModerateOpportunityCommand extends Command
{
    protected $signature = 'opportunities:moderate
        {opportunity : معرّف الفرصة}
        {action : suspend | withdraw | publish | show}
        {--note= : سبب القرار}';

    protected $description = 'Inspect, suspend, withdraw or republish a published project opportunity.';

    public function handle(): int
    {
        $opportunity = ProjectOpportunity::query()->with('activeVersion')->find($this->argument('opportunity'));
        if (! $opportunity) {
            $this->error('Opportunity not found.');

            return self::FAILURE;
        }

        $status = match ($this->argument('action')) {
            'suspend' => ProjectOpportunity::STATUS_SUSPENDED,
            'withdraw' => ProjectOpportunity::STATUS_WITHDRAWN,
            'publish' => ProjectOpportunity::STATUS_PUBLISHED,
            'show' => null,
            default => false,
        };
        if ($status === false) {
            $this->error('Action must be suspend, withdraw, publish or show.');

            return self::FAILURE;
        }

        if ($status !== null) {
            $opportunity->status = $status;
            $opportunity->status_changed_at = now();
            $opportunity->moderation_note = $this->option('note');
            $opportunity->published_at ??= $status === ProjectOpportunity::STATUS_PUBLISHED ? now() : null;
            $opportunity->save();
        }

        $this->table(['id', 'title', 'status', 'version', 'project_request_id', 'note'], [[
            $opportunity->id,
            $opportunity->title,
            $opportunity->status,
            $opportunity->activeVersion->version,
            $opportunity->project_request_id,
            $opportunity->moderation_note,
        ]]);

        return self::SUCCESS;
    }
}
