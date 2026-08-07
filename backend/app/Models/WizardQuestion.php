<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WizardQuestion extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'wizard_step_id',
        'key',
        'label',
        'input_type',
        'taxonomy_type',
        'session_field',
        'allow_free_text',
        'mandatory',
        'depends_on_taxonomy_node_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'allow_free_text' => 'boolean',
        'mandatory' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(WizardStep::class, 'wizard_step_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'depends_on_taxonomy_node_id');
    }

    /** Campaigns this question is attached to — see WizardCampaign::questions(). */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(WizardCampaign::class, 'wizard_campaign_questions')
            ->withPivot('sort_order');
    }
}
