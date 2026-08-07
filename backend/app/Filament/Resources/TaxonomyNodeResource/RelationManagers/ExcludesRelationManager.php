<?php

namespace App\Filament\Resources\TaxonomyNodeResource\RelationManagers;

class ExcludesRelationManager extends BaseTaxonomyEdgeRelationManager
{
    protected static string $relationship = 'excludes';

    protected static string $relationType = 'excludes';

    protected static ?string $title = 'Excludes';
}
