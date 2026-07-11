<?php
declare(strict_types=1);

namespace GeoFort\Services\Auth;

enum LoginStatus: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case LockedOut = 'locked_out';
}
