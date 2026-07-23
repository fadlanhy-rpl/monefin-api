<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
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
            'category_id'  => $this->category_id,
            'month'        => $this->month,
            'year'         => $this->year,
            'limit_amount' => $this->limit_amount,
            'spent_amount' => $this->spent_amount ?? '0.00',
            'category'     => new CategoryResource($this->whenLoaded('category')),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
