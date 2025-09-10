<?php
require_once(__DIR__ . '/class/common.php');

function displayErrorPage($error) {
    ob_end_clean();

    http_response_code(500);

    // User agent for less output
    if (isset($_SERVER['HTTP_USER_AGENT']) && str_starts_with($_SERVER['HTTP_USER_AGENT'], 'Offpost E2E Test')) {
        header('Content-Type: text/plain');
        echo 'Error during rendering of page ' . htmlescape($_SERVER['REQUEST_URI']) . "\n\n"
            . htmlescape(jTraceEx($error));
        exit;
    }
    header('Content-Type: text/html');
    
    echo '<html><head><title>Error - Offpost</title>';
    echo '<link rel="stylesheet" type="text/css" href="/css/error.css">';
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
    echo '<div class="nav-back"><a href="/">← Back to application</a></div>';
    echo '<p>An error occurred while processing your request. Please report this issue on our GitHub page:</p>';
    echo '<p><a href="https://github.com/HNygard/offpost/issues">https://github.com/HNygard/offpost/issues</a></p>';
    echo '<p>Error details:</p>';
    echo '<div class="error-details">';
    echo '<button class="copy-button">Copy error</button>';
    echo '<pre contenteditable="true">'
        . htmlescape(jTraceEx($error))
        . '</pre>';
    echo '</div>';
    echo '</div></body></html>';
    exit;
}
