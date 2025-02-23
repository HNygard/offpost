<?php

function displayErrorPage($error) {
    $statusCode = $error instanceof Exception ? ($error->getCode() ?: 500) : 500;
    http_response_code($statusCode);
    
    echo '<html><head><title>Error - Offpost</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .error-container { max-width: 800px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .header img { width: 32px; height: 32px; }
        .error-details {
            position: relative;
        }
        pre { 
            background: #f5f5f5; 
            padding: 15px; 
            border-radius: 5px; 
            overflow-x: auto;
            white-space: pre-wrap;
            margin: 0;
        }
        .copy-button {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            color: #666;
            transition: all 0.2s;
        }
        .copy-button:hover {
            background: #f0f0f0;
            border-color: #ccc;
        }
        a { color: #0366d6; }
    </style>';
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const copyButton = document.querySelector(".copy-button");
            const pre = document.querySelector("pre");

            copyButton.addEventListener("click", function() {
                const text = pre.textContent;
                navigator.clipboard.writeText(text).then(() => {
                    const originalText = this.textContent;
                    this.textContent = "Copied!";
                    this.style.backgroundColor = "#e6ffe6";
                    this.style.borderColor = "#a3d9a3";

                    setTimeout(() => {
                        this.textContent = originalText;
                        this.style.backgroundColor = "";
                        this.style.borderColor = "";
                    }, 1500);
                });
            });
        });
    </script>';
    echo '</head><body>';
    echo '<div class="error-container">';
    echo '<div class="header">';
    echo '<img src="/images/offpost-icon.webp" alt="Offpost Logo">';
    echo '<h1>Offpost - Error</h1>';
    echo '</div>';
    echo '<p>An error occurred while processing your request. Please report this issue on our GitHub page:</p>';
    echo '<p><a href="https://github.com/HNygard/offpost/issues">https://github.com/HNygard/offpost/issues</a></p>';
    echo '<p>Error details:</p>';
    echo '<div class="error-details">';
    echo '<button class="copy-button">Copy error</button>';
    echo '<pre contenteditable="true">'
        . htmlspecialchars($error->getMessage()
        . "\n\nStack trace:\n"
        . $error->getTraceAsString())
        . '</pre>';
    echo '</div>';
    echo '</div></body></html>';
    exit;
}
