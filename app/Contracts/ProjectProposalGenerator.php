<?php

namespace App\Contracts;

use App\Models\ProjectRequest;

interface ProjectProposalGenerator
{
    public function generate(ProjectRequest $projectRequest): array;
}
