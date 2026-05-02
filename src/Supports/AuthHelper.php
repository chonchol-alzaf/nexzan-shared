<?php
namespace Nexzan\Shared\Supports;

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
        ];
    }

    public function teamId(): ?string
    {
        return data_get($this->team(), 'id');

    }

    public function teamTitle(): ?string
    {
        return data_get($this->team(), 'title');
    }

    public function team()
    {
        return request()->attributes->get('team');
    }

}
