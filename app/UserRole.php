<?php

namespace App;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Finance = 'FINANCE';
    case Employee = 'EMPLOYEE';
}
