<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpendingNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'period_type'  => $this->period_type,
            'period_label' => $this->period_label,
            'spent_percent'=> $this->spent_percent,
            'message'      => $this->message,
            'is_read'      => $this->is_read,
            'created_at'   => $this->created_at,
        ];
    }
}
