<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'name'           => $this->name,
            'description'    => $this->description,
            'color'          => $this->color,
            'icon'           => $this->icon,
            'layout_type'    => $this->layout_type,
            'is_pinned'      => (bool) $this->is_pinned,
            'target_amount'  => $this->target_amount,
            'current_amount' => $this->current_amount,
            'progress'       => $this->target_amount > 0
                ? round(($this->current_amount / $this->target_amount) * 100, 2)
                : 0,
            'deadline'       => $this->deadline,
            'is_achieved'    => $this->current_amount >= $this->target_amount,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
