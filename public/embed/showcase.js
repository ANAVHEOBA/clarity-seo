(function() {
    'use strict';

    // Get the script tag that loaded this file
    const scriptTag = document.currentScript || document.querySelector('script[data-showcase]');
    
    if (!scriptTag) {
        console.error('Clarity SEO Embed: Script tag not found');
        return;
    }

    const showcaseKey = scriptTag.getAttribute('data-showcase');
    const theme = scriptTag.getAttribute('data-theme') || 'light';
    const layout = scriptTag.getAttribute('data-layout') || 'list';

    if (!showcaseKey) {
        console.error('Clarity SEO Embed: data-showcase attribute is required');
        return;
    }

    // Get the base URL from the script src
    const scriptSrc = scriptTag.src;
    const baseUrl = scriptSrc.substring(0, scriptSrc.indexOf('/embed/showcase.js'));

    // Create container
    const container = document.createElement('div');
    container.id = 'clarity-seo-showcase-' + showcaseKey;
    container.className = 'clarity-seo-showcase clarity-seo-theme-' + theme + ' clarity-seo-layout-' + layout;
    
    // Insert container after the script tag
    scriptTag.parentNode.insertBefore(container, scriptTag.nextSibling);

    // Fetch reviews
    fetch(baseUrl + '/api/v1/embed/' + showcaseKey + '/reviews?limit=10')
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to load reviews');
            }
            return response.json();
        })
        .then(data => {
            renderShowcase(container, data, theme, layout);
        })
        .catch(error => {
            console.error('Clarity SEO Embed:', error);
            container.innerHTML = '<p style="color: #999;">Unable to load reviews</p>';
        });

    function renderShowcase(container, data, theme, layout) {
        const { reviews, location, branding } = data;

        // Inject styles
        injectStyles(theme);

        // Build HTML
        let html = '<div class="clarity-seo-header">';
        html += '<h3 class="clarity-seo-title">Customer Reviews</h3>';
        if (location && location.name) {
            html += '<p class="clarity-seo-location">' + escapeHtml(location.name) + '</p>';
        }
        html += '</div>';

        if (reviews && reviews.length > 0) {
            html += '<div class="clarity-seo-reviews clarity-seo-reviews-' + layout + '">';
            
            reviews.forEach(review => {
                html += '<div class="clarity-seo-review">';
                html += '<div class="clarity-seo-review-header">';
                html += '<span class="clarity-seo-author">' + escapeHtml(review.author_name) + '</span>';
                html += '<span class="clarity-seo-rating">' + renderStars(review.rating) + '</span>';
                html += '</div>';
                if (review.comment) {
                    html += '<p class="clarity-seo-comment">' + escapeHtml(review.comment) + '</p>';
                }
                html += '<span class="clarity-seo-date">' + formatDate(review.created_at) + '</span>';
                html += '</div>';
            });
            
            html += '</div>';
        } else {
            html += '<p class="clarity-seo-no-reviews">No reviews yet</p>';
        }

        // Add branding if required
        if (branding && branding.show_logo) {
            html += '<div class="clarity-seo-branding">';
            html += '<a href="https://clarityseo.com" target="_blank" rel="noopener">';
            html += 'Powered by <strong>Clarity SEO</strong>';
            html += '</a>';
            html += '</div>';
        }

        container.innerHTML = html;
    }

    function renderStars(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        let stars = '';

        for (let i = 0; i < fullStars; i++) {
            stars += '★';
        }
        if (hasHalfStar) {
            stars += '☆';
        }
        const emptyStars = 5 - Math.ceil(rating);
        for (let i = 0; i < emptyStars; i++) {
            stars += '☆';
        }

        return stars;
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = Math.abs(now - date);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return diffDays + ' days ago';
        if (diffDays < 30) return Math.floor(diffDays / 7) + ' weeks ago';
        if (diffDays < 365) return Math.floor(diffDays / 30) + ' months ago';
        return Math.floor(diffDays / 365) + ' years ago';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function injectStyles(theme) {
        if (document.getElementById('clarity-seo-showcase-styles')) {
            return;
        }

        const isDark = theme === 'dark';
        const bgColor = isDark ? '#1a1a1a' : '#ffffff';
        const textColor = isDark ? '#e0e0e0' : '#333333';
        const borderColor = isDark ? '#333333' : '#e0e0e0';
        const mutedColor = isDark ? '#999999' : '#666666';

        const styles = `
            .clarity-seo-showcase {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                max-width: 800px;
                margin: 20px auto;
                padding: 20px;
                background: ${bgColor};
                border: 1px solid ${borderColor};
                border-radius: 8px;
                color: ${textColor};
            }
            .clarity-seo-header {
                margin-bottom: 20px;
                text-align: center;
            }
            .clarity-seo-title {
                margin: 0 0 8px 0;
                font-size: 24px;
                font-weight: 600;
            }
            .clarity-seo-location {
                margin: 0;
                color: ${mutedColor};
                font-size: 14px;
            }
            .clarity-seo-reviews-list {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .clarity-seo-reviews-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 16px;
            }
            .clarity-seo-review {
                padding: 16px;
                border: 1px solid ${borderColor};
                border-radius: 6px;
                background: ${isDark ? '#222222' : '#f9f9f9'};
            }
            .clarity-seo-review-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            .clarity-seo-author {
                font-weight: 600;
                font-size: 16px;
            }
            .clarity-seo-rating {
                color: #fbbf24;
                font-size: 18px;
            }
            .clarity-seo-comment {
                margin: 8px 0;
                line-height: 1.5;
                font-size: 14px;
            }
            .clarity-seo-date {
                font-size: 12px;
                color: ${mutedColor};
            }
            .clarity-seo-no-reviews {
                text-align: center;
                color: ${mutedColor};
                padding: 40px 20px;
            }
            .clarity-seo-branding {
                margin-top: 20px;
                padding-top: 16px;
                border-top: 1px solid ${borderColor};
                text-align: center;
                font-size: 12px;
            }
            .clarity-seo-branding a {
                color: ${mutedColor};
                text-decoration: none;
            }
            .clarity-seo-branding a:hover {
                color: ${textColor};
            }
            @media (max-width: 640px) {
                .clarity-seo-showcase {
                    padding: 16px;
                }
                .clarity-seo-reviews-grid {
                    grid-template-columns: 1fr;
                }
            }
        `;

        const styleSheet = document.createElement('style');
        styleSheet.id = 'clarity-seo-showcase-styles';
        styleSheet.textContent = styles;
        document.head.appendChild(styleSheet);
    }
})();
