<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\FilamentJevModeration\Tests\TestCase;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

pest()->extend(TestCase::class)->in('Feature');

function decisionFor(Model $model): ModerationDecisionFactory
{
    return ModerationDecisionFactory::new()->forModel($model);
}

function forumPost(string $title = 'Re: servicing', string $body = 'Only an idiot would pay that much.', string $author = 'Jordan Lee'): ForumPost
{
    return ForumPost::factory()
        ->for(User::factory()->state(['name' => $author]))
        ->createQuietly(['title' => $title, 'body' => $body]);
}
