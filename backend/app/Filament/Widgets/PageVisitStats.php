<?php

namespace App\Filament\Widgets;

use App\Models\PageVisit;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** "Kolko je ljudi bilo dnevno" — owner's ask, 2026-09-04, the day after launch. Plain counts
 *  off page_visits, no aggregation table needed at this volume. */
class PageVisitStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = PageVisit::whereDate('created_at', Carbon::today())->count();
        $yesterday = PageVisit::whereDate('created_at', Carbon::yesterday())->count();
        $last7Days = PageVisit::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $total = PageVisit::count();

        return [
            Stat::make('Today', $today),
            Stat::make('Yesterday', $yesterday),
            Stat::make('Last 7 days', $last7Days),
            Stat::make('All time', $total),
        ];
    }
}
