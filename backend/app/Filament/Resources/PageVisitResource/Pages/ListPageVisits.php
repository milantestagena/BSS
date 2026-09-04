<?php

namespace App\Filament\Resources\PageVisitResource\Pages;

use App\Filament\Pages\FunnelReport;
use App\Filament\Resources\PageVisitResource;
use App\Filament\Widgets\PageVisitStats;
use Filament\Actions\Action;
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

    // Owner's ask, 2026-09-05: a direct jump from "how many visits" to "where they drop off" —
    // the two admin views answer related questions, so link them instead of relying on the
    // sidebar nav alone.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewFunnel')
                ->label('View Funnel Report')
                ->icon('heroicon-o-funnel')
                ->url(FunnelReport::getUrl()),
        ];
    }
}
