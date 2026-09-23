<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'transaction_date'   => $this->transaction_date,
            'receipt_image_path' => $this->receipt_image_path,
            'receipt_image_url'  => $this->receipt_image_path
                ? $this->resolveReceiptImageUrl($this->receipt_image_path)
                : null,
            'receipt_data'       => $this->receipt_data,
            'account'            => new AccountResource($this->whenLoaded('account')),
            'category'           => new CategoryResource($this->whenLoaded('category')),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }

    /**
     * Resolve receipt image path to a publicly accessible URL.
     *
     * On shared hosting (e.g. Skipper), Laravel's url() and Storage::url()
     * helpers may inject "/index.php/" into the generated URL. Static files
     * (images) must be served directly by the web server without going through
     * index.php, so we strip that segment after URL generation.
     *
     * Handles:
     * 1. Full URL (e.g. from S3/CDN) → return as-is (strip index.php if present)
     * 2. Path with "uploads/" prefix → use uploads disk URL
     * 3. Other relative paths → use url() helper
     */
    private function resolveReceiptImageUrl(string $path): string
    {
        // Already a full URL (e.g. https://cdn.example.com/...)
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            // Still strip /index.php/ in case it was stored with it
            return str_replace('/index.php/', '/', $path);
        }

        // Path stored with "uploads/" prefix (our uploads disk paths)
        // e.g. "uploads/receipts/6/rcpt_6_...jpg"
        if (str_starts_with($path, 'uploads/')) {
            // Strip the "uploads/" prefix since the disk root IS public/uploads/
            $relativePath = substr($path, strlen('uploads/'));
            $url = Storage::disk('uploads')->url($relativePath);
        } else {
            // Fallback: build URL relative to app root
            $url = url($path);
        }

        // Strip /index.php/ injected by shared hosting Laravel configuration.
        // Static files in public/ must be served directly by Apache/Nginx,
        // not through Laravel's front controller (index.php).
        return str_replace('/index.php/', '/', $url);
    }
}
