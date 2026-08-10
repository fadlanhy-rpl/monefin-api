<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'user_id'      => $this->user_id,
            'name'         => $this->name,
            'type'         => $this->type,
            'icon'         => $this->icon,
            'description'  => $this->description,
            'color'        => $this->color ?? 'primary', // Default color if null
            'transactions' => $this->transactions_count ?? 0, // Loaded from withCount
            'realization'  => 0, // Default 0 as per implementation plan
            'is_default'   => is_null($this->user_id),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
