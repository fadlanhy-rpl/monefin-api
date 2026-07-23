<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->user_id,
            'account_id'       => $this->account_id,
            'category_id'      => $this->category_id,
            'goal_id'          => $this->goal_id,
            'type'             => $this->type,
            'amount'           => $this->amount,
            'description'      => $this->description,
            'transaction_date' => $this->transaction_date,
            'account'          => new AccountResource($this->whenLoaded('account')),
            'category'         => new CategoryResource($this->whenLoaded('category')),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
