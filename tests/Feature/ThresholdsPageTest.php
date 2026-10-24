<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\Enums\CalibrationAdvice;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Pages\Thresholds;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// The page runs the calibration pairing: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    Carbon::setTestNow('2026-10-23 12:00:00');
    JevModerationPlugin::get()->authorize(true)->overrideWarningRate(0.3)->minimumSample(4);
    $this->actingAs(User::factory()->create());
});

/**
 * Jev's result on a new forum post (or chat message) and a moderator's review of it.
 *
 * @param  array<string, mixed>  $jev
 */
function thresholdReview(string $status, string $review, string $category, float $confidence, array $jev = [], bool $chat = false, int $times = 1): void
{
    foreach (range(1, $times) as $ignored) {
        $record = $chat ? ChatMessage::factory()->createQuietly() : forumPost();

        decisionFor($record)->create([
            'status' => $status,
            'source' => ModerationSource::Jev,
            'category' => $category,
            'confidence' => $confidence,
            'policy' => $chat ? 'chat' : 'community',
            'meta' => ['reason' => 'review_threshold'],
            ...$jev,
        ]);
        decisionFor($record)->{$review}()->create();
    }
}

describe('registration and access', function (): void {
    it('is registered by the plugin after the review queue, with translated labels', function (): void {
        expect(Filament::getCurrentOrDefaultPanel()?->getPages())->toContain(Thresholds::class)
            ->and(Thresholds::getNavigationLabel())->toBe('Thresholds')
            ->and(Thresholds::getNavigationGroup())->toBe('Moderation')
            ->and(Thresholds::getNavigationSort())->toBe(2)
            ->and(Thresholds::getUrl())->toEndWith('/admin/moderation/thresholds');

        livewire(Thresholds::class)
            ->assertSee('Thresholds')
            ->assertSee('Read from config/jev-moderation.php. Change thresholds there; this page shows where moderators disagree with them.');
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(fn (): string => 'yes');

        expect(Thresholds::canAccess())->toBeFalse();
        livewire(Thresholds::class)->assertForbidden();
    });

    it('opens over HTTP', function (): void {
        $this->get(Thresholds::getUrl())->assertOk()->assertSee('Policy: community');
    });
});

describe('thresholds', function (): void {
    it('lists each policy\'s thresholds from config, in choice order, with the models using it', function (): void {
        decisionFor(forumPost())->flagged()->create();
        decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();

        livewire(Thresholds::class)
            ->assertSeeInOrder([
                'Policy: community', 'Used by Forum post',
                'Policy version', 'uncertain_below', '0.30', 'Bands to look at', '0',
                'Category', 'review_above', 'block_above',
                'Spam', '0.50', '0.90',
                'Harassment', '0.40', '0.95',
                'Hate', '0.30', '0.90',
                'Personal data', '0.40', 'Never',
                'Off topic', '0.80', 'Never',
                'Policy: chat', 'Used by Chat message', 'uncertain_below', 'Off',
                'Sexual', '0.50', '0.95',
            ])
            ->assertDontSee('Fine');
    });

    it('lists a policy no shown model uses', function (): void {
        livewire(Thresholds::class)->assertSee('No moderated model shown here uses this policy.');
    });

    it('shows the current policy version, in full on hover', function (): void {
        $version = JevModeration::policy('community')->version;

        livewire(Thresholds::class)
            ->assertSeeHtml('title="'.$version.'"')
            ->assertSee(substr($version, 0, 12));
    });

    it('explains a policy it cannot read, without failing the page', function (): void {
        config(['jev-moderation.policies.broken' => ['question' => 'Is this fine?', 'choices' => ['only'], 'safe_choice' => 'only']]);

        livewire(Thresholds::class)
            ->assertOk()
            ->assertSee('This policy could not be read from config: Moderation policy [broken] needs between 2 and')
            ->assertSee('Policy: community');
    });

    it('keeps a policy removed from config while calibration still has evidence for it', function (): void {
        thresholdReview('flagged', 'approved', 'spam', 0.55, ['policy' => 'retired']);
        config(['jev-moderation.policies' => Arr::except((array) config('jev-moderation.policies'), 'retired')]);

        livewire(Thresholds::class)
            ->assertSeeInOrder(['Policy: retired', 'This policy could not be read from config: No moderation policy named [retired]', 'Calibration bands', 'Spam', '0.5 – 0.6']);
    });
});

describe('calibration alongside', function (): void {
    it('shows every band under its policy, not only the flagged ones', function (): void {
        thresholdReview('flagged', 'approved', 'spam', 0.55, times: 4);
        thresholdReview('flagged', 'rejected', 'harassment', 0.65);

        livewire(Thresholds::class)
            ->assertSeeInOrder(['Policy: community', 'Bands to look at', '1', 'Calibration bands', 'Harassment', '0.6 – 0.7', 'Spam', '0.5 – 0.6', '100%']);
    });

    it('gives the rescan command for each model on the policy, naming the status the change affects', function (string $jevStatus, array $meta, string $category, float $confidence, string $advice, string $status): void {
        thresholdReview($jevStatus, 'approved', $category, $confidence, $meta === [] ? [] : ['meta' => $meta], times: 4);

        livewire(Thresholds::class)
            ->assertSee('After changing config, re-check the affected content with the command shown.')
            ->assertSee($advice)
            ->assertSeeHtml(e(sprintf('php artisan jev-moderation:rescan "%s" --status=%s', (new ForumPost)->getMorphClass(), $status)));
    })->with([
        'raise review_above' => ['flagged', [], 'spam', 0.55, 'consider raising review_above to 0.60', 'flagged'],
        'raise block_above' => ['blocked', [], 'spam', 0.93, 'consider raising block_above to 1.00', 'blocked'],
        'lower uncertain_below' => ['flagged', ['reason' => 'low_confidence', 'jev_confidence' => 0.25], 'fine', 0.25, 'consider lowering uncertain_below to 0.20', 'flagged'],
    ]);

    it('never suggests re-checking approved or rejected content', function (): void {
        foreach (CalibrationAdvice::cases() as $advice) {
            expect($advice->rescanStatus())->toBeIn(['flagged', 'blocked']);
        }
    });

    it('switches Jev version, falling back to the newest for an unknown one', function (): void {
        thresholdReview('flagged', 'approved', 'spam', 0.55, ['model' => 'jev-1.13.0'], times: 4);
        thresholdReview('flagged', 'rejected', 'spam', 0.65, ['model' => 'jev-1.14.0']);

        livewire(Thresholds::class)
            ->assertSeeHtml('<option value="jev-1.14.0" selected')
            ->assertDontSee('consider raising')
            ->set('model', 'jev-1.13.0')
            ->assertSee('consider raising review_above to 0.60')
            ->set('model', 'jev-0.1.0')
            ->assertSet('model', null)
            ->assertDontSee('consider raising');
    });

    it('says when there is nothing to calibrate yet', function (): void {
        livewire(Thresholds::class)
            ->assertSee('Bands fill in as moderators approve and reject content Jev checked.')
            ->assertDontSee('Calibration bands');
    });
});
