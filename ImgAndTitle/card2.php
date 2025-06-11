<?php
// This part of PHP runs when the page is initially loaded or when an AJAX request is made.

// Handle AJAX request for URL processing
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    $url = $_POST['url'] ?? '';
    $data = ['success' => false, 'message' => 'Invalid URL or failed to process.'];

    if (filter_var($url, FILTER_VALIDATE_URL)) {
        try {
            // --- Fetch HTML Content using cURL ---
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Return the transfer as a string
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Max redirects to follow
            curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout after 15 seconds
            // User-Agent: Some websites block requests without a proper user-agent
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            // Set some common headers to mimic a browser
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ]);
            // Optional: If you encounter SSL certificate issues (not recommended for production without proper CA cert setup)
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


            $html_content = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($html_content === false || $http_code >= 400) {
                $data['message'] = 'Failed to fetch content from URL. HTTP Code: ' . $http_code . ' Error: ' . $curl_error;
                echo json_encode($data);
                exit;
            }

            // --- News Metadata Extraction ---
            $headline = 'No Headline Found';
            $card_image = 'https://via.placeholder.com/600x400?text=No+Image+Available'; // Default placeholder
            $website_name = parse_url($url, PHP_URL_HOST) ?? 'Unknown Website';
            $favicon = 'https://www.google.com/s2/favicons?domain=' . urlencode($url); // Google's favicon service

            $dom = new DOMDocument();
            // Suppress errors for malformed HTML
            @$dom->loadHTML($html_content, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);
            $xpath = new DOMXPath($dom);

            // 1. Try to get Open Graph (OG) properties (most reliable for rich content)
            $og_title = $xpath->query('//meta[@property="og:title"]/@content')->item(0);
            if ($og_title) $headline = trim($og_title->nodeValue);

            $og_image = $xpath->query('//meta[@property="og:image"]/@content')->item(0);
            if ($og_image) {
                $card_image = trim($og_image->nodeValue);
            } else {
                // 2. Fallback to standard title if OG not found
                $title_tag = $xpath->query('//title')->item(0);
                if ($title_tag) $headline = trim($title_tag->nodeValue);

                // 3. Fallback to finding the first "large" image in the body
                // This is a very basic attempt. A more robust solution might check image dimensions.
                $first_img = $xpath->query('//body//img[not(contains(@width, "32")) and not(contains(@height, "32")) and not(contains(@class, "icon"))][1]/@src')->item(0);
                if ($first_img) {
                    $img_src = trim($first_img->nodeValue);
                    // Make sure the image URL is absolute
                    if (strpos($img_src, 'http') === 0 || strpos($img_src, '//') === 0) { // Check for http or protocol-relative
                        $card_image = $img_src;
                    } else {
                        // Relative URL, try to make it absolute
                        $base_url_parts = parse_url($url);
                        $scheme = $base_url_parts['scheme'] ?? 'http';
                        $host = $base_url_parts['host'] ?? '';
                        $path = $base_url_parts['path'] ?? '/';

                        // Handle paths properly for absolute URL construction
                        if (strpos($img_src, '/') === 0) { // Absolute path
                            $card_image = $scheme . '://' . $host . $img_src;
                        } else { // Relative path
                            $dir = dirname($path);
                            $card_image = $scheme . '://' . $host . ($dir === '.' ? '/' : $dir . '/') . $img_src;
                        }
                    }
                }
            }

            // Try to get site name from OG or title/meta
            $og_site_name = $xpath->query('//meta[@property="og:site_name"]/@content')->item(0);
            if ($og_site_name) $website_name = trim($og_site_name->nodeValue);
            // Fallback to hostname if no specific site name found
            else $website_name = parse_url($url, PHP_URL_HOST) ?? 'Unknown Website';


            $data = [
                'success' => true,
                'headline' => $headline,
                'card_image' => $card_image, // This is the image for the card
                'website_name' => $website_name,
                'original_url' => $url, // Include original URL for card linking
                'favicon' => $favicon // Favicon for website name
            ];

        } catch (Exception $e) {
            $data['message'] = 'Server error: ' . $e->getMessage();
            error_log('Error processing URL ' . $url . ': ' . $e->getMessage());
        }
    } else {
        $data['message'] = 'Please enter a valid URL.';
    }
    echo json_encode($data);
    exit; // Stop execution after AJAX response
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awesome News Card Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* This maintains the 3:2 aspect ratio for the image */
        .card-img-container {
            position: relative;
            width: 100%;
            padding-bottom: calc(2 / 3 * 100%); /* Height is 2/3 of width (3:2 ratio) */
            overflow: hidden;
            background-color: #e2e8f0; /* bg-slate-200 */
        }
        .card-img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* Ensures image covers the area, cropping if necessary */
            object-position: center;
        }

        /* NEW STYLES FOR OVERLAY TEXT */
        .card-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 100%);
            padding: 1rem; /* Adjust padding as needed */
            color: white;
            z-index: 10; /* Ensure it's above the image */
            display: flex;
            flex-direction: column;
            justify-content: flex-end; /* Push content to bottom */
            min-height: 50%; /* Give it some height for the gradient */
            transition: background 0.3s ease; /* Smooth transition for hover effect */
        }

        .card-img-container:hover .card-overlay {
            background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.1) 100%); /* Slightly darker on hover */
        }

        .card-overlay h2 {
            font-size: 1.5rem; /* Increased from 1.25rem (text-xl) to 1.5rem (text-2xl) */
            font-weight: bold;
            margin-bottom: 0.5rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3; /* Limit headline to 3 lines */
            overflow: hidden;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5); /* Subtle text shadow for readability */
        }

        .card-overlay a {
            display: inline-flex;
            align-items: center;
            font-size: 0.875rem; /* text-sm in Tailwind */
            color: #d1d5db; /* text-gray-300 */
            transition: color 0.2s ease;
        }

        .card-overlay a:hover {
            color: white;
        }

        .card-overlay img {
            filter: brightness(0.8) invert(1); /* Make favicon white/lighter for dark background */
        }


        /* Loader styles */
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            width: 30px;
            height: 30px;
            animation: spin 2s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Line clamping for text overflow */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-100 flex flex-col min-h-screen font-sans antialiased">

    <div class="container mx-auto p-4 flex-grow">
        <h1 class="text-3xl font-bold text-center mb-8 text-gray-800">Awesome News Card Generator</h1>

        <div class="bg-white p-6 shadow-md mb-8 max-w-2xl mx-auto">
            <form id="urlForm" class="flex flex-col sm:flex-row gap-4">
                <input type="url" id="urlInput" name="url" placeholder="Enter any website URL (e.g., https://example.com/news)"
                        class="flex-grow p-3 border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        required>
                <button type="submit"
                        class="bg-blue-600 text-white p-3 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 flex items-center justify-center">
                    <span id="buttonText">Generate Card</span>
                    <div id="loader" class="loader hidden ml-2"></div>
                </button>
            </form>
            <p id="errorMessage" class="text-red-500 text-sm mt-2 hidden"></p>
        </div>

        <div id="cardsContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        </div>
    </div>

    <footer class="bg-gray-800 text-white text-center p-4 mt-8">
        <p>&copy; <?php echo date('Y'); ?> News Card Generator. All rights reserved.</p>
    </footer>

    <script>
        // Function to truncate text and add ellipsis (retained but less critical with line-clamp)
        function truncateText(text, maxLength) {
            if (text.length <= maxLength) {
                return text;
            }
            // Find the last space before truncation point to avoid cutting words
            let truncated = text.substring(0, maxLength);
            let lastSpace = truncated.lastIndexOf(' ');
            if (lastSpace !== -1 && lastSpace > maxLength - 10) { // Only cut at space if it's close to the limit
                truncated = truncated.substring(0, lastSpace);
            }
            return truncated + '...';
        }

        document.getElementById('urlForm').addEventListener('submit', async function(event) {
            event.preventDefault();

            const urlInput = document.getElementById('urlInput');
            const url = urlInput.value.trim();
            const cardsContainer = document.getElementById('cardsContainer');
            const errorMessage = document.getElementById('errorMessage');
            const buttonText = document.getElementById('buttonText');
            const loader = document.getElementById('loader');

            errorMessage.classList.add('hidden');
            buttonText.textContent = 'Generating...';
            loader.classList.remove('hidden');
            this.querySelector('button').disabled = true; // Disable button during processing

            if (!url) {
                errorMessage.textContent = 'Please enter a URL.';
                errorMessage.classList.remove('hidden');
                buttonText.textContent = 'Generate Card';
                loader.classList.add('hidden');
                this.querySelector('button').disabled = false;
                return;
            }

            try {
                const formData = new FormData();
                formData.append('url', url);

                const response = await fetch('index.php', { // Send request back to the same page
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX request
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    const maxHeadlineLength = 120; // Increased max length for overlay
                    const displayHeadline = truncateText(data.headline, maxHeadlineLength);

                    // Generate a unique ID for each card to target it for screenshot
                    const cardId = 'card-' + Date.now();

                    const cardHtml = `
                        <div id="${cardId}" class="bg-gray-100 overflow-hidden relative shadow-md transform transition duration-300 hover:scale-105">
                            <a href="${data.original_url}" target="_blank" rel="noopener noreferrer">
                                <div class="card-img-container">
                                    <img src="${data.card_image}" alt="Image for ${data.website_name}" class="card-img"
                                            onerror="this.onerror=null;this.src='https://via.placeholder.com/600x400?text=Image+Load+Failed';">
                                    <div class="card-overlay">
                                        <h4 class="font-bold text-white mb-2 leading-tight">${displayHeadline}</h4>
                                        <div class="inline-flex items-center text-gray-300 text-sm">
                                            <img src="${data.favicon}" alt="Favicon" class="w-4 h-4 mr-2 filter brightness-0 invert">
                                            <span>${data.website_name}</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    `;
                    cardsContainer.insertAdjacentHTML('afterbegin', cardHtml); // Add new card at the top
                    urlInput.value = ''; // Clear input

                } else {
                    errorMessage.textContent = data.message || 'An unknown error occurred.';
                    errorMessage.classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error:', error);
                errorMessage.textContent = 'Failed to fetch or process URL. Please try again.';
                errorMessage.classList.remove('hidden');
            } finally {
                buttonText.textContent = 'Generate Card';
                loader.classList.add('hidden');
                this.querySelector('button').disabled = false;
            }
        });
    </script>
</body>
</html>
