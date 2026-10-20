# Davao Food Finder — conversational PHP prototype

A small server-rendered site using plain HTML/CSS/JavaScript + PHP. No Laravel, Node, npm, Vue, React, or frontend build step is required.

## Requirements
- PHP 8.1+ with `curl` and `mbstring` extensions
- Composer
- `zip`, `xml`, and `gd` PHP extensions may be required by PhpSpreadsheet for Excel files

## Setup
1. Extract this project folder.
2. Put all your dataset files in `data/`. CSV files named `restos.csv`, `reviews.csv`, `ph_restos_2025.csv`, `ph_reviews_2025.csv` or other `.csv` files are auto-detected. The project ZIP includes the two datasets previously uploaded in this conversation; if you have newer `restos.csv` / `reviews.csv` versions, copy them into `data/` too.
3. Install dependencies in the project directory:
   ```bash
   composer install
   ```
4. Set your Gemini API key as a server environment variable:
   - macOS/Linux:
     ```bash
     export GEMINI_API_KEY='paste-your-key-here'
     php -S 127.0.0.1:8000
     ```
   - Windows PowerShell:
     ```powershell
     $env:GEMINI_API_KEY = 'paste-your-key-here'
     php -S 127.0.0.1:8000
     ```
   Keep the key on the server. Never place it in `Main.html` or browser JavaScript, and do not commit a real key to a public repository. If a key was shared or committed, revoke it and create a replacement in Google AI Studio.
5. Start the server from the project directory:
   ```bash
   php -S 127.0.0.1:8000
   ```
6. Open http://127.0.0.1:8000/Main.html

No `php artisan serve` or `npm run dev` is needed. Those commands are for Laravel/Node projects, which this intentionally is not.

## What works
- Chat-style search with multi-turn follow-ups such as “show me more”, “what about Japanese food?”, or “similar but cheaper”.
- Gemini 3.8 uses Google's Interactions REST API to rank records from your local Davao City dataset and answer conversational follow-ups. No separate Gemini SDK install is needed for this PHP project.
- Results remain grouped under the query that produced them. Straightforward restaurant requests show results without repetitive canned replies; Gemini can respond conversationally when an answer is useful.
- CSVs are scanned in a streaming manner; imported place records are cached in `data/.places-cache.json` to avoid re-reading large files every query. The cache is rebuilt when dataset file names, sizes, or modified times change. To force a rebuild, delete that cache file.
- City filtering requires the detected `City` field to contain `Davao City`. Other Davao Region cities are excluded.
- Restaurant details show dataset ratings/reviewer counts and a Google Maps search link. Price and address details show “not available” if the dataset doesn't contain them.
- If Gemini is unconfigured or unavailable, relevant local matching is used as a fallback without a generic canned reply.

## Notes
- The model is configured as `gemini-3.8-flash`. The project calls the Interactions REST endpoint directly; check model access and API key status in Google AI Studio if Gemini cannot respond.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- This prototype does not fetch live web ratings. It uses the supplied data only and does not fabricate missing prices, addresses, or review scores.
