<?php

namespace Ngos\AdminCore\Services;

use Ngos\AdminCore\Models\Approval;

class ApprovalService extends BaseService
{
    public function __construct(Approval $approval)
    {
        $this->model = $approval;
    }
}
