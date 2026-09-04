<?php

namespace App\Models\Concerns;

use App\Models\RiskAlert;
use App\Models\RiskAssessment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared relationships for entities that carry TMS risk assessments and alerts
 * (AppUser, Transfer, VtpassPayment, NinePsbTransaction).
 */
trait RiskTagging
{
    public function riskAssessments(): MorphMany
    {
        return $this->morphMany(RiskAssessment::class, 'assessable');
    }

    public function riskAlerts(): MorphMany
    {
        return $this->morphMany(RiskAlert::class, 'assessable');
    }
}
