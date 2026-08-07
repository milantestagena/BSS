<?php

namespace App\Filament\Resources\WizardQuestionResource\Pages;

use App\Filament\Resources\WizardQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWizardQuestions extends ListRecords
{
    protected static string $resource = WizardQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
