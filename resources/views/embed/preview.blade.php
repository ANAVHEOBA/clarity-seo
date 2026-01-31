<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Embed Preview - Localmator</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 40px 20px;
            background: #f5f5f5;
        }
        .preview-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .preview-header {
            text-align: center;
            margin-bottom: 40px;
        }
        .preview-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
            color: #1a1a1a;
        }
        .preview-header p {
            color: #666;
            font-size: 16px;
        }
        .preview-content {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .code-section {
            margin-top: 40px;
            padding-top: 40px;
            border-top: 2px solid #e0e0e0;
        }
        .code-section h2 {
            font-size: 20px;
            margin-bottom: 16px;
            color: #1a1a1a;
        }
        .code-block {
            background: #1a1a1a;
            color: #e0e0e0;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
        }
        .copy-button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 12px;
            transition: background 0.2s;
        }
        .copy-button:hover {
            background: #1d4ed8;
        }
        .copy-button:active {
            background: #1e40af;
        }
        .options {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .option-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .option-group label {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .option-group select {
            padding: 8px 12px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="preview-header">
            <h1>🎨 Embed Preview</h1>
            <p>See how your review widget will look on your website</p>
        </div>

        <div class="preview-content">
            <div class="options">
                <div class="option-group">
                    <label for="theme-select">Theme:</label>
                    <select id="theme-select" onchange="updateEmbed()">
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>
                <div class="option-group">
                    <label for="layout-select">Layout:</label>
                    <select id="layout-select" onchange="updateEmbed()">
                        <option value="list">List</option>
                        <option value="grid">Grid</option>
                    </select>
                </div>
                <div class="option-group">
                    <label for="limit-select">Reviews:</label>
                    <select id="limit-select" onchange="updateEmbed()">
                        <option value="5">5 Reviews</option>
                        <option value="10" selected>10 Reviews</option>
                        <option value="20">20 Reviews</option>
                        <option value="50">50 Reviews</option>
                    </select>
                </div>
            </div>

            <div id="embed-container"></div>

            <div class="code-section">
                <h2>📋 Embed Code</h2>
                <p style="color: #666; margin-bottom: 16px;">Copy and paste this code into your website:</p>
                <div class="code-block" id="embed-code"></div>
                <button class="copy-button" onclick="copyCode()">Copy Code</button>
            </div>
        </div>
    </div>

    <script>
        const embedKey = '{{ $embedKey }}';
        const baseUrl = '{{ url('/') }}';

        function updateEmbed() {
            const theme = document.getElementById('theme-select').value;
            const layout = document.getElementById('layout-select').value;
            const limit = document.getElementById('limit-select').value;

            // Clear existing embed
            const container = document.getElementById('embed-container');
            container.innerHTML = '';

            // Create new script tag
            const script = document.createElement('script');
            script.src = baseUrl + '/embed/showcase.js';
            script.setAttribute('data-showcase', embedKey);
            script.setAttribute('data-theme', theme);
            script.setAttribute('data-layout', layout);
            script.setAttribute('data-limit', limit);
            container.appendChild(script);

            // Update code display
            const codeBlock = document.getElementById('embed-code');
            codeBlock.textContent = `<script src="${baseUrl}/embed/showcase.js" 
    data-showcase="${embedKey}" 
    data-theme="${theme}" 
    data-layout="${layout}"
    data-limit="${limit}"><\/script>`;
        }

        function copyCode() {
            const codeBlock = document.getElementById('embed-code');
            const text = codeBlock.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const button = document.querySelector('.copy-button');
                const originalText = button.textContent;
                button.textContent = '✓ Copied!';
                button.style.background = '#10b981';
                
                setTimeout(() => {
                    button.textContent = originalText;
                    button.style.background = '#2563eb';
                }, 2000);
            }).catch(err => {
                alert('Failed to copy code. Please copy manually.');
            });
        }

        // Initialize on load
        updateEmbed();
    </script>
</body>
</html>
