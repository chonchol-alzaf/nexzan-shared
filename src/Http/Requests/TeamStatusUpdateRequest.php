<?php

namespace Nexzan\Shared\Http\Requests;

use Nexzan\Shared\Http\Requests\BaseFormRequest;

class TeamStatusUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => 'required|integer|exists:teams,id',
            'status' => 'required|boolean',
        ];
    }
}
