<?php

declare(strict_types=1);

namespace Forgelabme\Ci4Updater\Config;

use CodeIgniter\Config\BaseService;
use Forgelabme\Ci4Updater\Updater;

/**
 * Auto-discovered by CodeIgniter's Modules system (the 'services' alias),
 * so `service('updater')` resolves to Updater without any app-side wiring.
 */
class Services extends BaseService
{
    public static function updater(bool $getShared = true): Updater
    {
        if ($getShared) {
            return self::getSharedInstance('updater');
        }

        return new Updater();
    }
}
