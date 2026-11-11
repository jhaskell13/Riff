<?php

namespace App\Models\Concerns;

use App\Contracts\HasPublicSlug;
use App\Support\Slugs;
use Illuminate\Database\Eloquent\Model;

/**
 * Public URL slugs whose links never break. The slug is model-managed,
 * never request-set: it is minted from the name on create, and a rename
 * mints a fresh one while parking the old slug in a history table, where
 * resolveRouteBinding keeps resolving it so the controller can 301 to the
 * canonical URL. Renaming back reclaims the parked slug.
 *
 * The using model implements App\Contracts\HasPublicSlug: slugHistory()
 * and slugFallback().
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements HasPublicSlug
 */
trait HasHistoricalSlugs
{
    public static function bootHasHistoricalSlugs(): void
    {
        static::creating(function (Model&HasPublicSlug $model): void {
            if (blank($model->getAttribute('slug'))) {
                $model->setAttribute('slug', Slugs::generate($model->getAttribute('name'), $model));
            }
        });

        static::updating(function (Model&HasPublicSlug $model): void {
            if (! $model->isDirty('name') || $model->isDirty('slug')) {
                return;
            }

            $current = $model->getAttribute('slug');
            $slug = Slugs::generate($model->getAttribute('name'), $model);

            if ($slug === $current) {
                return;
            }

            if ($current !== null) {
                $model->slugHistory()->firstOrCreate(['slug' => $current]);
            }

            $model->slugHistory()->where('slug', $slug)->delete();

            $model->setAttribute('slug', $slug);
        });
    }

    /**
     * Binding by slug also resolves historical slugs, to the record that
     * used to hold them. Soft-deleted records resolve to null (404) either
     * way, as default binding does.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        if ($field !== 'slug') {
            return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first();
        }

        $current = static::query()->where('slug', $value)->first();

        if ($current !== null) {
            return $current;
        }

        $history = $this->slugHistory();
        $ownerId = $history->getRelated()->newQuery()->where('slug', $value)->value($history->getForeignKeyName());

        return $ownerId === null ? null : static::query()->whereKey($ownerId)->first();
    }
}
