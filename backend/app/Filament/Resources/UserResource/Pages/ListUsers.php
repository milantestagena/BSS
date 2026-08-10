<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ListRecords;

// No CreateAction — users are created via Google OAuth (see GoogleAuthController), not
// manually in admin.
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
