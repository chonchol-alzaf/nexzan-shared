<?php

namespace Nexzan\Shared\Http\Requests;

use Nexzan\Shared\Enums\TeamStatusEnum;
use Illuminate\Validation\Rule;
use Nexzan\Shared\Http\Requests\BaseFormRequest;

class TeamStatusUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'team_id' => 'required|integer|exists:teams,id',
            'status' => ['required',Rule::in(TeamStatusEnum::getValues())],
        ];
    }
}
