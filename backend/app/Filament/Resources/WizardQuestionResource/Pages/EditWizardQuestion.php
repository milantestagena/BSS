<?php

namespace App\Filament\Resources\WizardQuestionResource\Pages;

use App\Filament\Resources\WizardQuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWizardQuestion extends EditRecord
{
    protected static string $resource = WizardQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
