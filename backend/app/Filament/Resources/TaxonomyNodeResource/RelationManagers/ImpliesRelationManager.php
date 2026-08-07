<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

class ImpliesRelationManager extends BaseTaxonomyEdgeRelationManager
{
    protected static string $relationship = 'implies';

    protected static string $relationType = 'implies';

    protected static ?string $title = 'Implies';
}
