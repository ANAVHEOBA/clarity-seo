(function() {
    'use strict';

    // Get the script tag that loaded this file
    const scriptTag = document.currentScript || document.querySelector('script[data-showcase]');
    
    if (!scriptTag) {
        console.error('Localmator Embed: Script tag not found');
        return;
    }

    const showcaseKey = scriptTag.getAttribute('data-showcase');
    const theme = scriptTag.getAttribute('data-theme') || 'light';
    const layout = scriptTag.getAttribute('data-layout') || 'list';
    const limit = scriptTag.getAttribute('data-limit') || '10';

    if (!showcaseKey) {
        console.error('Localmator Embed: data-showcase attribute is required');
        return;
    }

    // Get the base URL from the script src
    const scriptSrc = scriptTag.src;
    const baseUrl = scriptSrc.substring(0, scriptSrc.indexOf('/embed/showcase.js'));

    // Create iframe container
    const container = document.createElement('div');
    container.id = 'localmator-showcase-' + showcaseKey;
    container.className = 'localmator-showcase-container';
    container.style.cssText = 'width: 100%; max-width: 800px; margin: 20px auto;';
    
    // Create iframe
    const iframe = document.createElement('iframe');
    iframe.src = baseUrl + '/api/v1/embed/' + showcaseKey + '/reviews?theme=' + theme + '&layout=' + layout + '&limit=' + limit;
    iframe.style.cssText = 'width: 100%; border: none; min-height: 400px;';
    iframe.setAttribute('scrolling', 'no');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('title', 'Customer Reviews');
    
    // Auto-resize iframe based on content
    iframe.onload = function() {
        try {
            // Try to access iframe content height
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            const resizeIframe = function() {
                const height = iframeDoc.body.scrollHeight;
                iframe.style.height = height + 'px';
            };
            
            // Initial resize
            resizeIframe();
            
            // Watch for content changes
            if (window.ResizeObserver) {
                const observer = new ResizeObserver(resizeIframe);
                observer.observe(iframeDoc.body);
            }
        } catch (e) {
            // Cross-origin restrictions - use default height
            iframe.style.height = '600px';
        }
    };
    
    container.appendChild(iframe);
    
    // Insert container after the script tag
    scriptTag.parentNode.insertBefore(container, scriptTag.nextSibling);

    // Listen for messages from iframe for height adjustment (cross-origin safe)
    window.addEventListener('message', function(event) {
        if (event.origin !== baseUrl) return;
        
        if (event.data && event.data.type === 'localmator-resize') {
            iframe.style.height = event.data.height + 'px';
        }
    });
})();
