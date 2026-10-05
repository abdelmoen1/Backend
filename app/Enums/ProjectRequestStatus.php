<?php

namespace App\Enums;

final class ProjectRequestStatus
{
    public const DRAFT = 'draft';

    public const INTAKE_IN_PROGRESS = 'intake_in_progress';

    public const ANALYZING = 'analyzing';

    public const PROPOSAL_READY = 'proposal_ready';

    public const REVISION_REQUESTED = 'revision_requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CONVERTED_TO_OPPORTUNITY = 'converted_to_opportunity';
}
