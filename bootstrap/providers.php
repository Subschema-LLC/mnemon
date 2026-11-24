<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Mnemon\Delivery\Wiring;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    Wiring::class,
];
