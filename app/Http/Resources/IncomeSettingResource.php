<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomeSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'user_id'             => $this->user_id,
            'type'                => $this->type,
            'title'               => $this->title,
            'account_id'          => $this->account_id,
            'category_id'         => $this->category_id,
            'amount'              => $this->amount,
            'period_type'         => $this->period_type,
            'is_active'           => $this->is_active,
            'effective_date'      => $this->effective_date,
            'last_processed_date' => $this->last_processed_date,
            'account'             => $this->whenLoaded('account', function () {
                if (!$this->account) {
                    return null;
                }
                return [
                    'id'          => $this->account->id,
                    'name'        => $this->account->name,
                    'type'        => $this->account->type,
                    'color_theme' => $this->account->color_theme,
                ];
            }),
            'category'            => $this->whenLoaded('category', function () {
                if (!$this->category) {
                    return null;
                }
                return [
                    'id'    => $this->category->id,
                    'name'  => $this->category->name,
                    'type'  => $this->category->type,
                    'color' => $this->category->color ?? null,
                ];
            }),
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
