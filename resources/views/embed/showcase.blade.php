<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - {{ $location->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            @if($theme === 'dark')
            background: #1a1a1a;
            color: #e0e0e0;
            @else
            background: #ffffff;
            color: #333333;
            @endif
        }

        .showcase-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .showcase-header {
            margin-bottom: 24px;
            text-align: center;
        }

        .showcase-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .showcase-location {
            @if($theme === 'dark')
            color: #999999;
            @else
            color: #666666;
            @endif
            font-size: 14px;
        }

        .reviews-container {
            @if($layout === 'grid')
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            @else
            display: flex;
            flex-direction: column;
            gap: 16px;
            @endif
        }

        .review-card {
            @if($theme === 'dark')
            background: #222222;
            border: 1px solid #333333;
            @else
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            @endif
            border-radius: 8px;
            padding: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .review-card:hover {
            transform: translateY(-2px);
            @if($theme === 'dark')
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            @else
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            @endif
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .review-author {
            font-weight: 600;
            font-size: 16px;
        }

        .review-rating {
            color: #fbbf24;
            font-size: 18px;
            letter-spacing: 2px;
        }

        .review-content {
            line-height: 1.6;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .review-date {
            @if($theme === 'dark')
            color: #999999;
            @else
            color: #666666;
            @endif
            font-size: 12px;
        }

        .no-reviews {
            text-align: center;
            @if($theme === 'dark')
            color: #999999;
            @else
            color: #666666;
            @endif
            padding: 60px 20px;
            font-size: 16px;
        }

        .branding {
            margin-top: 24px;
            padding-top: 20px;
            @if($theme === 'dark')
            border-top: 1px solid #333333;
            @else
            border-top: 1px solid #e0e0e0;
            @endif
            text-align: center;
            font-size: 13px;
        }

        .branding a {
            @if($theme === 'dark')
            color: #999999;
            @else
            color: #666666;
            @endif
            text-decoration: none;
            transition: color 0.2s;
        }

        .branding a:hover {
            @if($theme === 'dark')
            color: #e0e0e0;
            @else
            color: #333333;
            @endif
        }

        .branding strong {
            font-weight: 600;
            @if($theme === 'dark')
            color: #3b82f6;
            @else
            color: #2563eb;
            @endif
        }

        @media (max-width: 640px) {
            .showcase-container {
                padding: 16px;
            }

            .reviews-container {
                grid-template-columns: 1fr !important;
            }

            .showcase-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="showcase-container">
        <div class="showcase-header">
            <h1 class="showcase-title">Customer Reviews</h1>
            <p class="showcase-location">{{ $location->name }}</p>
        </div>

        @if($reviews->count() > 0)
            <div class="reviews-container">
                @foreach($reviews as $review)
                    <div class="review-card">
                        <div class="review-header">
                            <span class="review-author">{{ $review->author_name ?? 'Anonymous' }}</span>
                            <span class="review-rating">
                                @for($i = 1; $i <= 5; $i++)
                                    @if($i <= $review->rating)
                                        ★
                                    @else
                                        ☆
                                    @endif
                                @endfor
                            </span>
                        </div>
                        @if($review->content)
                            <p class="review-content">{{ Str::limit($review->content, 200) }}</p>
                        @endif
                        <span class="review-date">
                            {{ $review->published_at ? $review->published_at->diffForHumans() : $review->created_at->diffForHumans() }}
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="no-reviews">
                <p>No reviews yet. Be the first to leave a review!</p>
            </div>
        @endif

        @if($showLogo)
            <div class="branding">
                <a href="https://localmator.com" target="_blank" rel="noopener noreferrer">
                    Powered by <strong>Localmator</strong>
                </a>
            </div>
        @endif
    </div>

    <script>
        // Send height to parent window for iframe resizing
        function sendHeight() {
            const height = document.body.scrollHeight;
            window.parent.postMessage({
                type: 'localmator-resize',
                height: height
            }, '*');
        }

        // Send initial height
        if (window.parent !== window) {
            sendHeight();
            
            // Watch for content changes
            if (window.ResizeObserver) {
                const observer = new ResizeObserver(sendHeight);
                observer.observe(document.body);
            }
        }
    </script>
</body>
</html>
