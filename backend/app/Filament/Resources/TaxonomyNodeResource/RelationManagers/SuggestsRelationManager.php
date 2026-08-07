<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

class SuggestsRelationManager extends BaseTaxonomyEdgeRelationManager
{
    protected static string $relationship = 'suggests';

    protected static string $relationType = 'suggests';

    protected static ?string $title = 'Suggests';
}
