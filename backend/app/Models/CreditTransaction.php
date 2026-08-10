<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Audit log of every wallet change — see CLAUDE.md section 5. `amount` is signed: positive for
// credits (welcome/booking/manual_bonus), negative for spend (ai_query).
#[Fillable(['user_id', 'amount', 'type', 'description'])]
class CreditTransaction extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
