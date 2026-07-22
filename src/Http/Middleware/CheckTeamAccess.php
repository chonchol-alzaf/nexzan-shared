<?php

namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Nexzan\Shared\Enums\AccountStatusEnum;
use Nexzan\Shared\Enums\BillingStatusEnum;
use Nexzan\Shared\Enums\TeamAccessCapabilityEnum;
use Nexzan\Shared\Exceptions\CustomException;
use Nexzan\Shared\Facades\Auth;

class CheckTeamAccess
{
    public function handle($request, Closure $next, string $capability)
    {
        if (! Auth::canTeam($capability)) {
            throw new CustomException($this->message($capability), 403);
        }

        return $next($request);
    }

    private function message(string $capability): string
    {
        if (! Auth::teamId()) {
            return 'You cannot perform this action because the team context is missing.';
        }

        return match (Auth::accountStatus()) {
            AccountStatusEnum::TERMINATED->value => 'You cannot perform this action because your account is terminated.',
            AccountStatusEnum::SUSPENDED->value => 'You cannot perform this action because your account is suspended. Please contact support.',
            default => $this->billingMessage($capability),
        };
    }

    private function billingMessage(string $capability): string
    {
        return match (Auth::billingStatus()) {
            BillingStatusEnum::HOLD->value => $this->holdMessage($capability),
            BillingStatusEnum::SUSPENDED->value => 'You cannot perform this action because your billing is suspended. Please pay your due invoice or update your payment method.',
            default => 'You cannot perform this action because your team does not have the required access.',
        };
    }

    private function holdMessage(string $capability): string
    {
        if ($capability === TeamAccessCapabilityEnum::CREATE_RESOURCE->value) {
            return 'This action is unavailable because your team has an outstanding balance. Please pay the pending invoice before creating or upgrading resources.';
        }

        if ($capability === TeamAccessCapabilityEnum::USE_RESOURCE->value) {
            return 'You cannot perform this action because your team can only view resources right now. Please resolve billing to continue.';
        }

        return 'You cannot perform this action because your team has a due balance.';
    }
}
