<?php
// Keep the API key in the server environment, never in browser code or source control.
$envKey = getenv('GEMINI_API_KEY');
define('GEMINI_API_KEY', $envKey !== false ? trim($envKey) : '');
define('GEMINI_MODEL', 'gemini-3.8-flash');
