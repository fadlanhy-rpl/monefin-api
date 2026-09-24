<?php
/**
 * MoneFin — Patch: Fix Receipt Image URL on Shared Hosting
 *
 * Problem: Laravel's url() and Storage::url() inject "/index.php/" into
 *          generated URLs on Skipper shared hosting, making receipt images
 *          return 404 even though the file exists in public/uploads/receipts/.
 *
 * Fix:     Strip "/index.php/" from receipt_image_url in TransactionResource.
 *
 * Upload to : monefin-backend/public/patch_receipt_image_url.php
 * Access via: https://sk0010uoic.skipper.my.id/patch_receipt_image_url.php
 * DELETE this file after use!
 */

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
echo "=== MoneFin Patch: Fix Receipt Image URL ===\n\n";

// ── Update TransactionResource.php ─────────────────────────────────────────
$targetFile = $base . '/app/Http/Resources/TransactionResource.php';

$newContent = <<<'PHP'
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
     * 2. Path with "uploads/" prefix  → use uploads disk URL
     * 3. Other relative paths         → use url() helper
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
PHP;

// ── Write file ──────────────────────────────────────────────────────────────
echo "Target: {$targetFile}\n";

if (!file_exists($targetFile)) {
    echo "❌ ERROR: File tidak ditemukan: {$targetFile}\n";
    exit(1);
}

// Backup file lama
$backupFile = $targetFile . '.bak_' . date('YmdHis');
if (!copy($targetFile, $backupFile)) {
    echo "⚠ WARNING: Gagal membuat backup, melanjutkan...\n";
} else {
    echo "✅ Backup dibuat: {$backupFile}\n";
}

// Tulis file baru
$written = file_put_contents($targetFile, $newContent);
if ($written === false) {
    echo "❌ ERROR: Gagal menulis file. Periksa permission folder.\n";
    exit(1);
}

echo "✅ TransactionResource.php berhasil diperbarui ({$written} bytes ditulis)\n\n";

// ── Clear Laravel config cache ───────────────────────────────────────────────
echo "── Membersihkan cache Laravel... ──\n";

// Clear bootstrap/cache/config.php
$configCache = $base . '/bootstrap/cache/config.php';
if (file_exists($configCache)) {
    unlink($configCache);
    echo "✅ Config cache dihapus\n";
} else {
    echo "ℹ Config cache tidak ada (sudah bersih)\n";
}

// Clear bootstrap/cache/services.php
$servicesCache = $base . '/bootstrap/cache/services.php';
if (file_exists($servicesCache)) {
    unlink($servicesCache);
    echo "✅ Services cache dihapus\n";
} else {
    echo "ℹ Services cache tidak ada\n";
}

// Clear bootstrap/cache/packages.php
$packagesCache = $base . '/bootstrap/cache/packages.php';
if (file_exists($packagesCache)) {
    unlink($packagesCache);
    echo "✅ Packages cache dihapus\n";
}

// Clear file-based cache
$cacheDir = $base . '/storage/framework/cache/data';
if (is_dir($cacheDir)) {
    $cacheFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $cleared = 0;
    foreach ($cacheFiles as $f) {
        if ($f->isFile()) {
            unlink($f->getPathname());
            $cleared++;
        }
    }
    echo "✅ App cache dihapus ({$cleared} files)\n";
}

// ── Verify fix ──────────────────────────────────────────────────────────────
echo "\n── Verifikasi ──\n";
$content = file_get_contents($targetFile);
if (str_contains($content, "str_replace('/index.php/', '/', \$url)")) {
    echo "✅ Fix sudah diterapkan dengan benar!\n";
    echo "\n=== SELESAI ===\n";
    echo "Gambar struk sekarang harus bisa tampil di monefin.web.id\n";
    echo "HAPUS file patch_receipt_image_url.php ini setelah selesai!\n";
} else {
    echo "❌ Verifikasi GAGAL — konten file tidak sesuai ekspektasi.\n";
    echo "Kemungkinan ada masalah saat penulisan file.\n";
}
