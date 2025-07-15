<?php

namespace Nexzan\Shared\Http\Controllers\MicroService\Team;

use App\Http\Controllers\Controller;
use Nexzan\Shared\Http\Requests\TeamStatusUpdateRequest;
use Nexzan\Shared\Services\MicroService\Team\TeamService;

class TeamController extends Controller
{
    public function __construct(private TeamService $team_service) {}

    public function updateTeamStatus(TeamStatusUpdateRequest $request)
    {
        return $this->team_service->updateTeamStatus($request);
    }
}
