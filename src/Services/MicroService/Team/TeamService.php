<?php

namespace Nexzan\Shared\Services\MicroService\Team;


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

            $teamStatusUpdateJob = config('nexzan-shared.jobs.team_status_update');
            if (class_exists($teamStatusUpdateJob)) {
                dispatch(new $teamStatusUpdateJob($request->validated()));
            }


            return ResponseSuccess([], 'Team status updated successfully!');
        } catch (\Throwable $th) {

            return ResponseError($th->getMessage(), 500, $th);
        }
    }
}
