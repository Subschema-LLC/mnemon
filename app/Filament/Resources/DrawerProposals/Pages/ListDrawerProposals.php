<?php

namespace App\Filament\Resources\DrawerProposals\Pages;

use App\Filament\Resources\DrawerProposals\DrawerProposalResource;
use Filament\Resources\Pages\ListRecords;

class ListDrawerProposals extends ListRecords
{
    protected static string $resource = DrawerProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
