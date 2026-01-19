<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\ReviewResponse;
use Google\Client;
use Google\Service\YouTube;
use Google\Service\YouTube\Comment;
use Google\Service\YouTube\CommentSnippet;
use Google\Service\YouTube\CommentThread;
use Illuminate\Support\Facades\Log;

class YouTubeReviewService
{
    protected ?YouTube $youtubeService = null;

    /**
     * Set the YouTube service instance (mainly for testing).
     */
    public function setYouTubeService(YouTube $service): void
    {
        $this->youtubeService = $service;
    }

    /**
     * Get the YouTube service instance.
     */
    protected function getYouTubeService(PlatformCredential $credential): YouTube
    {
        if ($this->youtubeService) {
            return $this->youtubeService;
        }

        $client = new Client();
        $client->setClientId(config('services.youtube.client_id') ?? env('YOUTUBE_CLIENT_ID'));
        $client->setClientSecret(config('services.youtube.client_secret') ?? env('YOUTUBE_CLIENT_SECRET'));
        $client->setAccessToken([
            'access_token' => $credential->access_token,
            'refresh_token' => $credential->refresh_token,
            'expires_in' => $credential->expires_at ? $credential->expires_at->diffInSeconds(now()) : 3600,
        ]);

        if ($client->isAccessTokenExpired() && $credential->refresh_token) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($credential->refresh_token);
            if (!isset($newToken['error'])) {
                $credential->update([
                    'access_token' => $newToken['access_token'],
                    'expires_at' => now()->addSeconds($newToken['expires_in']),
                ]);
            }
        }

        return new YouTube($client);
    }

    /**
     * Sync reviews (comments) from YouTube for a given location.
     */
    public function syncReviews(Location $location, PlatformCredential $credential): int
    {
        $channelId = $location->youtube_channel_id;

        if (!$channelId) {
            Log::warning('YouTube sync skipped: No channel ID for location', ['location_id' => $location->id]);
            return 0;
        }

        try {
            $service = $this->getYouTubeService($credential);
            $response = $service->commentThreads->listCommentThreads('snippet,replies', [
                'allThreadsRelatedToChannelId' => $channelId,
                'maxResults' => 50,
            ]);

            $syncedCount = 0;

            foreach ($response->getItems() as $thread) {
                /** @var CommentThread $thread */
                $topComment = $thread->getSnippet()->getTopLevelComment();
                $snippet = $topComment->getSnippet();

                Review::updateOrCreate(
                    [
                        'location_id' => $location->id,
                        'platform' => PlatformCredential::PLATFORM_YOUTUBE,
                        'external_id' => $topComment->getId(),
                    ],
                    [
                        'author_name' => $snippet->getAuthorDisplayName(),
                        'author_image' => $snippet->getAuthorProfileImageUrl(),
                        'rating' => null, // YouTube comments don't have ratings
                        'content' => $snippet->getTextDisplay(),
                        'published_at' => \Carbon\Carbon::parse($snippet->getPublishedAt()),
                        'metadata' => [
                            'thread_id' => $thread->getId(),
                            'video_id' => $thread->getSnippet()->getVideoId(),
                            'channel_id' => $thread->getSnippet()->getChannelId(),
                            'like_count' => $snippet->getLikeCount(),
                        ],
                    ]
                );

                $syncedCount++;
            }

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('YouTube sync failed', [
                'location_id' => $location->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Reply to a YouTube comment.
     */
    public function replyToReview(Review $review, string $content, PlatformCredential $credential): bool
    {
        if ($review->platform !== PlatformCredential::PLATFORM_YOUTUBE) {
            return false;
        }

        try {
            $service = $this->getYouTubeService($credential);

            $commentSnippet = new CommentSnippet();
            $commentSnippet->setParentId($review->external_id);
            $commentSnippet->setTextOriginal($content);

            $comment = new Comment();
            $comment->setSnippet($commentSnippet);

            $response = $service->comments->insert('snippet', $comment);

            if ($response && $response->getId()) {
                $review->response()->updateOrCreate(
                    ['review_id' => $review->id],
                    [
                        'content' => $content,
                        'status' => 'published',
                        'platform_synced' => true,
                        'metadata' => array_merge($review->response->metadata ?? [], [
                            'youtube_reply_id' => $response->getId(),
                        ]),
                    ]
                );

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('YouTube reply failed', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
