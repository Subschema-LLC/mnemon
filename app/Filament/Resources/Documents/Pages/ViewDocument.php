<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentActions;
use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocumentActions::download(),
            DocumentActions::retry(),
            DocumentActions::delete(),
        ];
    }
}
