<?php

namespace OneMediaLabs\MixpostMcp\Support;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonStatus
{
    public function __construct(
        private readonly ?MasterSupervisorRepository $masterSupervisorRepository = null
    ) {}

    public function get(): string
    {
        // The desktop build never registers Horizon, so the repository is not bound and the
        // container hands the constructor null.
        if ($this->masterSupervisorRepository === null) {
            return 'Not installed';
        }

        if (! $masters = $this->masterSupervisorRepository->all()) {
            return 'Inactive';
        }

        if (collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        })) {
            return 'Paused';
        }

        return 'Active';
    }
}
