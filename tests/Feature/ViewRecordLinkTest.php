<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\Resources\ForumPostResource;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->migrateModerationTables();

    JevModerationPlugin::get()->authorize(true);
    $this->actingAs(User::factory()->create());
});

afterEach(function (): void {
    ForumPostResource::$canView = true;
    ForumPostResource::$canEdit = true;
});

/**
 * The queue row for a decision, as the table loads it.
 */
function queueRow(ModerationDecision $decision): ModerationDecision
{
    $row = livewire(ListReviewQueue::class)->instance()->getTable()->getRecords()->firstWhere('id', $decision->id);

    expect($row)->toBeInstanceOf(ModerationDecision::class);

    return $row;
}

describe('default', function (): void {
    it('links to the record\'s view page in the host resource, in a new tab', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();
        $url = ForumPostResource::getUrl('view', ['record' => $post]);

        expect($url)->toEndWith("/admin/forum-posts/{$post->getKey()}");

        livewire(ListReviewQueue::class)
            ->assertTableActionVisible('view_record', $flag)
            ->assertTableActionHasUrl('view_record', $url, $flag)
            ->assertTableActionShouldOpenUrlInNewTab('view_record', $flag);
    });

    it('falls back to the edit page when the user may not view the record', function (): void {
        ForumPostResource::$canView = false;
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();

        expect(JevModerationPlugin::get()->getRecordUrl(queueRow($flag)))->toBe(ForumPostResource::getUrl('edit', ['record' => $post]));
    });

    it('is hidden when the user may neither view nor edit the record', function (): void {
        ForumPostResource::$canView = false;
        ForumPostResource::$canEdit = false;
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableActionHidden('view_record', $flag);
    });

    it('is hidden when the host resource has no page for one record', function (): void {
        $flag = decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableActionHidden('view_record', $flag);
    });

    it('is hidden when the panel has no resource for the model', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();
        $row = queueRow($flag);
        $plugin = JevModerationPlugin::get();

        Filament::setCurrentPanel(Panel::make()->id('host'));

        expect($plugin->getRecordUrl($row))->toBeNull();
    });

    it('is null when the moderated record is not loaded', function (): void {
        expect(JevModerationPlugin::get()->getRecordUrl(new ModerationDecision))->toBeNull();
    });
});

describe('recordUrlUsing()', function (): void {
    it('replaces the default, with the record and decision injected by name and type', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();

        JevModerationPlugin::get()->recordUrlUsing(
            fn (Model $record, ModerationDecision $decision, ForumPost $typed): string => "https://forum.test/posts/{$record->getKey()}?flag={$decision->id}&same=".($typed->is($record) ? 'yes' : 'no'),
        );

        livewire(ListReviewQueue::class)
            ->assertTableActionHasUrl('view_record', "https://forum.test/posts/{$post->getKey()}?flag={$flag->id}&same=yes", $flag);
    });

    it('hides the link when the closure returns null or an empty string', function (?string $url): void {
        JevModerationPlugin::get()->recordUrlUsing(fn (): ?string => $url);
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableActionHidden('view_record', $flag);
    })->with([null, '']);

    it('goes back to the default when cleared', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();

        JevModerationPlugin::get()->recordUrlUsing(fn (): string => 'https://elsewhere.test')->recordUrlUsing(null);

        expect(JevModerationPlugin::get()->getRecordUrl(queueRow($flag)))->toBe(ForumPostResource::getUrl('view', ['record' => $post]));
    });

    it('rejects anything but a string or null', function (): void {
        $row = queueRow(decisionFor(forumPost())->flagged()->create());
        JevModerationPlugin::get()->recordUrlUsing(fn (): int => 42);

        expect(fn (): ?string => JevModerationPlugin::get()->getRecordUrl($row))
            ->toThrow(InvalidArgumentException::class, 'The Jev Moderation record URL must resolve to a string or null, int given.');
    });
});
