<?php
namespace Nexzan\Shared\Supports;

use Nexzan\Shared\Enums\TeamAccessCapabilityEnum;

class AuthHelper
{
    public function authUser()
    {
        return request()->attributes->get('auth_user');
    }

    public function userPermissions()
    {
        return data_get($this->authUser(), 'permissions');
    }

    public function check()
    {
        return $this->id() ? true : false;
    }

    public function id()
    {
        return data_get($this->authUser(), 'id');
    }

    public function email(): ?string
    {
        return data_get($this->authUser(), 'email');
    }

    public function name(): ?string
    {
        return data_get($this->authUser(), 'name');
    }

    public function user()
    {
        return (object) [
            'id'    => $this->id(),
            'email' => $this->email(),
            'name'  => $this->name(),
            'type'  => data_get($this->authUser(), 'type') ?? "user",
        ];
    }

    public function type()
    {
        return data_get($this->authUser(), 'type') ?? "user";
    }

    public function teamId(): ?string
    {
        return data_get($this->team(), 'id');

    }

    public function teamTitle(): ?string
    {
        return data_get($this->team(), 'title');
    }

    public function accountStatus(): ?string
    {
        return data_get($this->team(), 'account_status');
    }

    public function billingStatus(): ?string
    {
        return data_get($this->team(), 'billing_status');
    }

    public function teamGrace(): ?array
    {
        $grace = data_get($this->team(), 'grace');

        return $this->toArray($grace);
    }

    public function teamAccess(): array
    {
        $access = data_get($this->team(), 'effective_access');

        return $this->toArray($access) ?? [];
    }

    public function canTeam(string|TeamAccessCapabilityEnum $capability): bool
    {
        $capability = $capability instanceof TeamAccessCapabilityEnum
            ? $capability->value
            : $capability;

        if (! in_array($capability, TeamAccessCapabilityEnum::values(), true)) {
            return false;
        }

        return data_get($this->teamAccess(), $capability) === true;
    }

    public function team()
    {
        return request()->attributes->get('team');
    }

    private function toArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        return null;
    }

}
