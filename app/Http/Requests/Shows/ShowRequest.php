<?php

namespace App\Http\Requests\Shows;

use App\Models\Show;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating and editing a show share these rules. Authorization is the
 * controller's (ShowPolicy, plus ArtistPolicy for the host on create).
 *
 * Times arrive as the local date and clock time the show's own poster would
 * write, plus an IANA timezone, and are converted to UTC here. Nothing else
 * in the app has to think about where the show is.
 */
class ShowRequest extends FormRequest
{
    private const OPTIONAL_TEXT_FIELDS = [
        'title', 'location_name', 'city', 'state', 'country', 'location_note',
        'doors_time', 'price', 'ticket_url', 'age_policy', 'description',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $normalised[$field] = null;
            }
        }

        if ($this->input('venue_id') === '' || $this->input('venue_id') === 0) {
            $normalised['venue_id'] = null;
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->route('show') === null;

        return [
            // Only on create: a show never changes hands.
            'host_artist_id' => $isCreate
                ? ['required', 'integer', Rule::exists('artists', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            'title' => ['nullable', 'string', 'max:120'],

            // A venue, or a one-off location of the show's own: exactly one.
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->whereNull('deleted_at')],
            'location_name' => ['nullable', 'required_without:venue_id', 'prohibits:venue_id', 'string', 'max:120'],
            'city' => ['nullable', 'required_without:venue_id', 'prohibits:venue_id', 'string', 'max:120'],
            'state' => ['nullable', 'required_without:venue_id', 'prohibits:venue_id', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'location_note' => ['nullable', 'string', 'max:255'],

            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'doors_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['required', 'string', 'timezone:all'],

            'price' => ['nullable', 'string', 'max:40'],
            'ticket_url' => ['nullable', 'url:http,https', 'max:255'],
            'age_policy' => ['nullable', 'string', Rule::in(array_keys(config('riff.age_policies')))],
            'description' => ['nullable', 'string', 'max:2000'],

            'lineup' => ['required', 'array', 'min:1', 'max:'.config('riff.shows.max_lineup')],
            'lineup.*' => ['array:artist_id,name'],
            'lineup.*.artist_id' => ['nullable', 'integer', 'distinct', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            'lineup.*.name' => ['nullable', 'required_without:lineup.*.artist_id', 'prohibits:lineup.*.artist_id', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $show = $this->route('show');
                $hostId = $show instanceof Show ? $show->host_artist_id : (int) $this->input('host_artist_id');
                $lineupArtistIds = $this->collect('lineup')->pluck('artist_id')->filter()->map(fn ($id) => (int) $id);

                // The host plays its own show: that is what hosting means,
                // and it keeps every listing tied to an artist who agreed.
                if (! $lineupArtistIds->contains($hostId)) {
                    $validator->errors()->add('lineup', 'The hosting artist has to be on the bill.');
                }

                $startsAt = $this->startsAt();

                // Only a new or moved time must be in the future: fixing a
                // typo in tonight's listing after the doors open is fine.
                $timeChanged = ! $show instanceof Show || ! $startsAt->equalTo($show->starts_at);

                if ($timeChanged && $startsAt->isPast()) {
                    $validator->errors()->add('date', 'The show has to be in the future.');
                }

                if ($startsAt->gt(now()->addDays((int) config('riff.shows.max_days_ahead')))) {
                    $validator->errors()->add('date', 'Shows can be listed up to a year ahead.');
                }

                $doorsAt = $this->doorsAt();

                if ($doorsAt !== null && $doorsAt->gt($startsAt)) {
                    $validator->errors()->add('doors_time', 'Doors open before the show starts.');
                }
            },
        ];
    }

    /** The start, in UTC. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $this->input('date').' '.$this->input('start_time'),
            (string) $this->input('timezone'),
        )->utc();
    }

    /** Doors, in UTC, on the same local date as the start. */
    public function doorsAt(): ?CarbonImmutable
    {
        if (blank($this->input('doors_time'))) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $this->input('date').' '.$this->input('doors_time'),
            (string) $this->input('timezone'),
        )->utc();
    }

    /**
     * The validated fields in the model's terms.
     *
     * @return array<string, mixed>
     */
    public function showAttributes(): array
    {
        return [
            ...$this->safe()->only([
                'title', 'venue_id', 'location_name', 'city', 'state', 'country',
                'location_note', 'timezone', 'price', 'ticket_url', 'age_policy', 'description',
            ]),
            'starts_at' => $this->startsAt(),
            'doors_at' => $this->doorsAt(),
        ];
    }

    /**
     * @return list<array{artist_id: int|null, name: string|null}>
     */
    public function lineup(): array
    {
        return $this->safe()->collect('lineup')
            ->map(fn (array $entry) => [
                'artist_id' => isset($entry['artist_id']) ? (int) $entry['artist_id'] : null,
                'name' => $entry['name'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function attributes(): array
    {
        return [
            'host_artist_id' => 'hosting artist',
            'venue_id' => 'venue',
            'location_name' => 'place name',
            'start_time' => 'start time',
            'doors_time' => 'doors time',
            'ticket_url' => 'ticket link',
            'age_policy' => 'age policy',
            'lineup.*.name' => 'act name',
        ];
    }
}
