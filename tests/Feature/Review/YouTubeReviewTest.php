<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Review\YouTubeReviewService;
use Google\Service\YouTube;
use Google\Service\YouTube\Comment;
use Google\Service\YouTube\CommentSnippet;
use Google\Service\YouTube\CommentThread;
use Google\Service\YouTube\CommentThreadListResponse;
use Google\Service\YouTube\CommentThreadSnippet;
use Google\Service\YouTube\Resource\CommentThreads;
use Google\Service\YouTube\Resource\Comments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class YouTubeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Location $location;
    protected PlatformCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        
        // Setup location with YouTube channel ID
        $this->location = Location::factory()->create([
            'tenant_id' => $this->tenant->id,
            'youtube_channel_id' => 'UC_x5XG1OV2P6uZZ5FSM9Ttw'
        ]);

        // Setup Platform Credential for YouTube
        $this->credential = PlatformCredential::factory()->create([
            'tenant_id' => $this->tenant->id,
            'platform' => PlatformCredential::PLATFORM_YOUTUBE,
            'access_token' => 'fake_access_token',
            'refresh_token' => 'fake_refresh_token',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    public function test_it_can_sync_youtube_comments_as_reviews()
    {
        // 1. Setup Mock Data
        $snippet = new CommentSnippet();
        $snippet->setTextDisplay('Great video!');
        $snippet->setAuthorDisplayName('John YouTuber');
        $snippet->setAuthorProfileImageUrl('https://example.com/avatar.jpg');
        $snippet->setLikeCount(10);
        $snippet->setPublishedAt(now()->toIso8601String());
        
        // Top Level Comment
        $topLevelComment = new Comment();
        $topLevelComment->setId('comment_123');
        $topLevelComment->setSnippet($snippet);

        // Thread Snippet
        $threadSnippet = new CommentThreadSnippet();
        $threadSnippet->setTopLevelComment($topLevelComment);
        $threadSnippet->setVideoId('video_abc');
        $threadSnippet->setCanReply(true);

        // Comment Thread
        $thread = new CommentThread();
        $thread->setId('thread_123');
        $thread->setSnippet($threadSnippet);

        // Response
        $listResponse = new CommentThreadListResponse();
        $listResponse->setItems([$thread]);

        // 2. Mock Google Service
        $threadsResource = Mockery::mock(CommentThreads::class);
        $threadsResource->shouldReceive('listCommentThreads')
            ->with('snippet,replies', Mockery::on(function ($params) {
                return $params['allThreadsRelatedToChannelId'] === 'UC_x5XG1OV2P6uZZ5FSM9Ttw';
            }))
            ->once()
            ->andReturn($listResponse);

        $youtubeService = Mockery::mock(YouTube::class);
        $youtubeService->commentThreads = $threadsResource;

        // 3. Execute Service
        // We'll need to allow injecting the mock service, or mocking the factory that creates it
        $service = new YouTubeReviewService();
        $service->setYouTubeService($youtubeService); // We'll need to add this setter

        $count = $service->syncReviews($this->location, $this->credential);

        // 4. Assertions
        $this->assertEquals(1, $count);
        
        $this->assertDatabaseHas('reviews', [
            'location_id' => $this->location->id,
            'platform' => 'youtube',
            'external_id' => 'comment_123', // We use the comment ID, not thread ID
            'author_name' => 'John YouTuber',
            'content' => 'Great video!',
            'rating' => null, // YouTube doesn't have ratings
        ]);

        $review = Review::where('external_id', 'comment_123')->first();
        $this->assertEquals('video_abc', $review->metadata['video_id']);
    }

    public function test_it_can_reply_to_youtube_comment()
    {
        // 1. Setup Data
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'youtube',
            'external_id' => 'comment_123',
            'metadata' => ['video_id' => 'video_abc']
        ]);

        // 2. Mock Google Service
        $responseComment = new Comment();
        $responseComment->setId('reply_456');
        
        $responseSnippet = new CommentSnippet();
        $responseSnippet->setTextDisplay('Thanks for watching!');
        $responseComment->setSnippet($responseSnippet);

        $commentsResource = Mockery::mock(Comments::class);
        $commentsResource->shouldReceive('insert')
            ->with('snippet', Mockery::on(function ($comment) {
                return $comment->getSnippet()->getParentId() === 'comment_123'
                    && $comment->getSnippet()->getTextOriginal() === 'Thanks for watching!';
            }))
            ->once()
            ->andReturn($responseComment);

        $youtubeService = Mockery::mock(YouTube::class);
        $youtubeService->comments = $commentsResource;

        // 3. Execute Service
        $service = new YouTubeReviewService();
        $service->setYouTubeService($youtubeService);

        $success = $service->replyToReview($review, 'Thanks for watching!', $this->credential);

        // 4. Assertions
        $this->assertTrue($success);
        
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'content' => 'Thanks for watching!',
            'status' => 'published',
            'platform_synced' => true
        ]);
    }
}
