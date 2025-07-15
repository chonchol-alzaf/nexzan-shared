<?php

namespace Nexzan\Shared\Services\MicroService\Team;

use Nexzan\Shared\Models\Team;

class TeamService
{
    public function updateTeamStatus($request)
    {
        try {

            $teamModel = config('nexzan-shared.models.team');
            if (!class_exists($teamModel)) {
                throw new \RuntimeException("Model class [$teamModel] does not exist.");
            }
            
            $teamModel::where('id', $request->team_id)->update([
                'status' => $request->status,
            ]);

            return ResponseSuccess([], 'Team status updated successfully!');
        } catch (\Throwable $e) {

            return ResponseError($e->getMessage());
        }
    }
}
