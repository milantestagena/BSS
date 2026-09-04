<?php

namespace App\Filament\Resources\PageVisitResource\Pages;

use App\Filament\Resources\PageVisitResource;
use App\Filament\Widgets\PageVisitStats;
use Filament\Resources\Pages\ListRecords;

class ListPageVisits extends ListRecords
{
    protected static string $resource = PageVisitResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PageVisitStats::class,
        ];
    }
}
