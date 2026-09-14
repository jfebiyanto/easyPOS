<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Catat aktivitas: siapa yang melakukan, apa yang diubah, dan kapan.
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        ?array $properties = null
    ): void {
        $user = request()->user('sanctum') ?? auth()->user();

        ActivityLog::create([
            'company_id' => $user?->company_id ?? current_company_id(),
            'actor_id' => $user?->getKey(),
            'actor_name' => $user?->name ?? 'system',
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}