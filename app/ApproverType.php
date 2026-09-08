<?php

namespace App;

enum ApproverType: string
{
    case RequesterManager = 'REQUESTER_MANAGER';
    case DepartmentManager = 'DEPARTMENT_MANAGER';
    case Role = 'ROLE';
    case SpecificUser = 'SPECIFIC_USER';

    public function label(): string
    {
        return match ($this) {
            self::RequesterManager => 'Requester manager',
            self::DepartmentManager => 'Department manager',
            self::Role => 'Role',
            self::SpecificUser => 'Specific user',
        };
    }
}
