<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

function normalizeText($value): string {
    if (is_array($value) || is_object($value)) return '';
    return trim(preg_replace('/\s+/u', ' ', (string)$value));
}
function normKey(string $s): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
}
function findValue(array $row, array $needles): string {
    foreach ($row as $key => $value) {
        $k = normKey((string)$key);
        foreach ($needles as $needle) {
            if (strpos($k, normKey($needle)) !== false && normalizeText($value) !== '') return normalizeText($value);
        }
    }
    return '';
}
/** Stream CSV rows so the large reviews file does not have to be loaded into memory at once. */
function csvRows(string $file): Generator {
    $handle = fopen($file, 'rb');
    if (!$handle) return;
    $first = fgets($handle);
    if ($first === false) { fclose($handle); return; }
    // Detect common delimiters from the header line.
    $delimiter = ',';
    foreach ([',', ';', "\t", '|'] as $candidate) {
        if (substr_count($first, $candidate) > substr_count($first, $delimiter)) $delimiter = $candidate;
    }
    rewind($handle);
    $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if (!$headers) { fclose($handle); return; }
    $headers = array_map(fn($v) => normalizeText($v), $headers);
    while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (count($values) === 1 && ($values[0] === null || trim((string)$values[0]) === '')) continue;
        $row = [];
        foreach ($headers as $i => $header) if ($header !== '') $row[$header] = normalizeText($values[$i] ?? '');
        yield $row;
    }
    fclose($handle);
}
function worksheetRows(string $file): Generator {
    $book = IOFactory::load($file);
    foreach ($book->getWorksheetIterator() as $sheet) {
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) continue;
        $headers = array_map(fn($v) => normalizeText($v), array_shift($rows));
        foreach ($rows as $values) {
            $row = [];
            foreach ($headers as $i => $header) if ($header !== '') $row[$header] = normalizeText($values[$i] ?? '');
            if ($row) yield $row;
        }
    }
    $book->disconnectWorksheets();
}
function isDavaoCity(array $row): bool {
    $city = findValue($row, ['city','municipality','locality','citymunicipality']);
    // This dataset uses "davao city davao del sur". Match Davao City, not other Davao Region cities.
    return preg_match('/\bdavao\s+city\b/i', $city) === 1;
}
function loadDavaoPlaces(string $dir): array {
    $files = glob($dir . '/*.{xlsx,xls,csv}', GLOB_BRACE) ?: [];
    $reviewStats = [];
    $restaurantRows = [];

    // First pass: aggregate reviews by StoreId, keeping memory use low.
    foreach ($files as $file) {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'csv') continue;
        try {
            $first = true;
            foreach (csvRows($file) as $row) {
                if ($first) {
                    $keys = array_map('normKey', array_keys($row));
                    $first = false;
                    // The review export has StoreId + overall + restaurant_food, but no restaurant name/city.
                    $isReviewFile = in_array('storeid', $keys, true)
                        && (in_array('restaurantfood', $keys, true) || in_array('overall', $keys, true))
                        && !in_array('completestorename', $keys, true);
                    if (!$isReviewFile) break;
                }
                $storeId = findValue($row, ['storeid']);
                if ($storeId === '') continue;
                $score = findValue($row, ['restaurant_food']);
                if ($score === '') $score = findValue($row, ['overall']);
                if (!isset($reviewStats[$storeId])) $reviewStats[$storeId] = ['count'=>0,'sum'=>0.0,'scored'=>0];
                $reviewStats[$storeId]['count']++;
                if (is_numeric($score) && (float)$score > 0 && (float)$score <= 5) {
                    $reviewStats[$storeId]['sum'] += (float)$score;
                    $reviewStats[$storeId]['scored']++;
                }
            }
        } catch (Throwable $e) {
            error_log('Could not aggregate reviews in ' . basename($file) . ': ' . $e->getMessage());
        }
    }

    // Second pass: load restaurant records from CSV/Excel files.
    foreach ($files as $file) {
        try {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $rows = $extension === 'csv' ? csvRows($file) : worksheetRows($file);
            foreach ($rows as $assoc) {
                $name = findValue($assoc, ['completestorename','restaurantname','businessname','placename','establishmentname','name']);
                if ($name === '') continue;
                // Only rows with a City column explicitly identifying Davao City are included.
                if (!isDavaoCity($assoc)) continue;
                $storeId = findValue($assoc, ['storeid']);
                $address = findValue($assoc, ['fulladdress','completeaddress','address','location','street']);
                $foodType = findValue($assoc, ['foodtype','cuisine','category','specialty','offerings']);
                $ratingText = findValue($assoc, ['averagerating','starrating','reviewscore','rating']);
                $reviewCountText = findValue($assoc, ['reviewers','reviewcount','numberofreviews','totalreviews']);
                $price = findValue($assoc, ['pricerange','pricelevel','averageprice']);
                $description = findValue($assoc, ['description','about']);
                if ($description === '') $description = $foodType;

                $rating = is_numeric($ratingText) && (float)$ratingText > 0 ? (float)$ratingText : null;
                $reviewCount = is_numeric($reviewCountText) ? (int)$reviewCountText : null;
                if ($storeId !== '' && isset($reviewStats[$storeId])) {
                    $stats = $reviewStats[$storeId];
                    if ($reviewCount === null || $reviewCount === 0) $reviewCount = $stats['count'];
                    if ($rating === null && $stats['scored'] > 0) $rating = round($stats['sum'] / $stats['scored'], 1);
                }
                $record = [
                    'name'=>$name, 'address'=>$address, 'city'=>findValue($assoc, ['city']),
                    'food_type'=>$foodType, 'price_range'=>$price, 'description'=>$description,
                    'rating'=>$rating, 'review_count'=>$reviewCount, 'maps_url'=>'',
                    'store_id'=>$storeId, 'source'=>basename($file)
                ];
                // StoreId alone is not unique for every branch; deduplicate on name + city/address.
                $record['_id'] = hash('sha256', strtolower($name.'|'.$address.'|'.$record['city']));
                $restaurantRows[] = $record;
            }
        } catch (Throwable $e) {
            error_log('Could not read dataset ' . basename($file) . ': ' . $e->getMessage());
        }
    }

    $places = []; $seen = [];
    foreach ($restaurantRows as $record) {
        if (isset($seen[$record['_id']])) {
            $idx = $seen[$record['_id']];
            foreach (['address','food_type','price_range','description','rating','review_count','maps_url'] as $field) {
                if (($places[$idx][$field] === '' || $places[$idx][$field] === null || $places[$idx][$field] === 0) && $record[$field] !== '' && $record[$field] !== null && $record[$field] !== 0) $places[$idx][$field] = $record[$field];
            }
        } else {
            $seen[$record['_id']] = count($places);
            $places[] = $record;
        }
    }
    return $places;
}
function shortlistPlaces(array $places, string $query, int $limit): array {
    $stopWords = array_fill_keys([
        'a','about','and','are','around','best','can','city','davao','find','for','food',
        'give','good','in','me','near','of','options','place','places','please','recommend',
        'restaurant','restaurants','show','some','the','to','what','where','with','you'
    ], true);
    $terms = array_values(array_filter(
        preg_split('/[^\pL\pN]+/u', mb_strtolower($query)),
        fn($word) => mb_strlen($word) > 1 && !isset($stopWords[$word])
    ));
    $scored = [];
    foreach ($places as $p) {
        $haystack = mb_strtolower(implode(' ', [$p['name'],$p['food_type'] ?? '',$p['description'],$p['address'],$p['price_range']]));
        $score = 0;
        foreach ($terms as $term) {
            if (mb_strpos($haystack, $term) !== false) $score += (mb_strpos(mb_strtolower($p['name']), $term) !== false) ? 4 : 1;
        }
        // Mild boosts for cuisine/category records directly matching common food phrases.
        if (preg_match('/fried\s+chicken/i', $query) && preg_match('/fried chicken|chicken/i', $haystack)) $score += 2;
        if ($score > 0) $scored[] = ['score'=>$score, 'place'=>$p];
    }
    usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
    if ($terms) {
        return array_map(fn($item) => $item['place'], array_slice($scored, 0, $limit));
    }

    // For broad requests with no specific search terms, prefer the best-rated records.
    $rated = array_values(array_filter($places, fn($place) => is_numeric($place['rating'] ?? null)));
    usort($rated, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: (($b['review_count'] ?? 0) <=> ($a['review_count'] ?? 0)));
    return array_slice($rated ?: $places, 0, $limit);
}
function answerWithGemini(string $query, array $history, array $candidates): array {
    $fallbackIds = array_map(fn($p) => $p['_id'], $candidates);
    if (GEMINI_API_KEY === '') return ['', $fallbackIds, 'not_configured'];

    $compact = [];
    foreach ($candidates as $p) $compact[] = [
        'id'=>$p['_id'], 'name'=>$p['name'], 'food_type'=>$p['food_type'] ?? '',
        'description'=>$p['description'], 'address'=>$p['address'], 'city'=>$p['city'] ?? '',
        'price_range'=>$p['price_range'], 'rating'=>$p['rating'], 'review_count'=>$p['review_count']
    ];
    $transcript = [];
    foreach ($history as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
        $content = trim((string)($turn['content'] ?? ''));
        if ($content !== '') $transcript[] = $role . ': ' . mb_substr($content, 0, 1200);
    }
    $prompt = "You are Davao Food Finder, a conversational guide to restaurants and food places in Davao City. Use only the supplied records and conversation. Interpret follow-ups in context. Never invent ratings, prices, addresses, reviews, or restaurants. Rank only records that fit the user's request. If there are no candidate records for a recommendation request, say briefly that no matching records were found and do not suggest unlisted places. For messages unrelated to finding places, respond naturally and briefly, then guide the conversation back to Davao food discovery when appropriate. Return JSON matching the requested schema. Keep answer empty for a straightforward request for recommendations when matching cards are provided; the interface displays them, so do not repeat or introduce them. Write a brief, natural answer only when the user asks a conversational question or needs clarification. Do not add generic invitations or repeated boilerplate.\n\nConversation:\n"
        . ($transcript ? implode("\n", $transcript) . "\n" : '')
        . "Latest user request: {$query}\n\nCandidate records:\n"
        . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $url = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    $payload = json_encode([
        'model'=>GEMINI_MODEL,
        'input'=>$prompt,
        'response_format'=>[
            'type'=>'text',
            'mime_type'=>'application/json',
            'schema'=>[
                'type'=>'object',
                'properties'=>[
                    'answer'=>['type'=>'string'],
                    'ids'=>['type'=>'array','items'=>['type'=>'string']]
                ],
                'required'=>['answer','ids']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY, 'Api-Revision: 2026-05-20'],
        CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>50
    ]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('Gemini API error HTTP ' . $status . ': ' . ($curlError ?: (string)$raw));
        return ['', $fallbackIds, 'error'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !is_array($decoded['steps'] ?? null)) {
        error_log('Gemini returned an invalid Interactions API response.');
        return ['', $fallbackIds, 'error'];
    }
    $text = '';
    foreach (($decoded['steps'] ?? []) as $step) {
        if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') continue;
        foreach (($step['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') $text .= (string)($part['text'] ?? '');
        }
    }
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !is_array($parsed['ids'] ?? null) || !is_string($parsed['answer'] ?? null)) {
        error_log('Gemini returned an invalid structured response.');
        return ['', $fallbackIds, 'error'];
    }
    $allowed = array_fill_keys($fallbackIds, true); $ids = [];
    foreach (($parsed['ids'] ?? []) as $id) if (is_string($id) && isset($allowed[$id]) && !in_array($id, $ids, true)) $ids[] = $id;
    $answer = trim((string)($parsed['answer'] ?? ''));
    return [$answer, $ids ?: $fallbackIds, 'ready'];
}
function publicPlace(array $p): array { return $p; }
