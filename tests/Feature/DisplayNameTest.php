<?php

declare(strict_types=1);

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\FilamentJevModeration\Support\DisplayName;

/**
 * A person model, a panel user when given a Filament name. getFilamentName()
 * returns a plain string: Filament 4 declares `string`, 5 `string|Htmlable`.
 *
 * @param  array<string, mixed>  $attributes
 */
function person(array $attributes, ?string $filamentName = null): Model
{
    $model = $filamentName === null
        ? new class extends Model {}
    : new class extends Model implements HasName
    {
        public string $filamentName = '';

        public function getFilamentName(): string
        {
            return $this->filamentName;
        }
    };

    if ($filamentName !== null) {
        $model->filamentName = $filamentName;
    }

    return $model->forceFill($attributes);
}

it('uses Filament\'s name for a panel user', function (): void {
    expect(DisplayName::of(person(['id' => 3, 'name' => 'Ignored'], 'Sam Patel')))->toBe('Sam Patel');
});

it('uses the name attribute, else the key', function (): void {
    expect(DisplayName::of(person(['id' => 4, 'name' => 'Jordan Lee'])))->toBe('Jordan Lee')
        ->and(DisplayName::of(person(['id' => 5, 'name' => ''])))->toBe('#5')
        ->and(DisplayName::of(person([])))->toBe('#');
});
