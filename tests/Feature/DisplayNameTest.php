<?php

declare(strict_types=1);

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Phoenix1331\FilamentJevModeration\Support\DisplayName;

/**
 * @param  array<string, mixed>  $attributes
 */
function person(array $attributes, string|HtmlString|null $filamentName = null): Model
{
    $model = $filamentName === null
        ? new class extends Model {}
    : new class extends Model implements HasName
    {
        public string|HtmlString $filamentName = '';

        public function getFilamentName(): string|HtmlString
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
    expect(DisplayName::of(person(['id' => 3, 'name' => 'Ignored'], 'Sam Patel')))->toBe('Sam Patel')
        ->and(DisplayName::of(person(['id' => 3], new HtmlString('<b>Sam</b>'))))->toBe('<b>Sam</b>');
});

it('uses the name attribute, else the key', function (): void {
    expect(DisplayName::of(person(['id' => 4, 'name' => 'Jordan Lee'])))->toBe('Jordan Lee')
        ->and(DisplayName::of(person(['id' => 5, 'name' => ''])))->toBe('#5')
        ->and(DisplayName::of(person([])))->toBe('#');
});
