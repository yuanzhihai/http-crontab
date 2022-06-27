<?php

namespace Fairy;

use Fairy\command\Crontab;

class Service extends \think\Service
{

    public function boot()
    {
        $this->commands([
            Crontab::class,
        ]);
    }
}
