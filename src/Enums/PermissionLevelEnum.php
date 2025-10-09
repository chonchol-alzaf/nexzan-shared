<?php
namespace Nexzan\Shared\Enums;

use BenSampo\Enum\Enum;

final class PermissionLevelEnum extends Enum
{
    const full   = "full";
    const manage = "manage";
    const view   = "view";
    const deny   = "deny";
}
