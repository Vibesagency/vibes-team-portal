<?php

defined('ABSPATH') || exit;

final class VPM_Cron {
    public static function boot(): void {
        add_action('vpm_daily_lead_scan', [self::class, 'daily_scan']);
    }

    public static function daily_scan(): void {
        global $wpdb;
        $ids = $wpdb->get_col('SELECT id FROM ' . VPM_Permissions::table('crm_sources') . ' WHERE active=1 ORDER BY id ASC');
        foreach ($ids as $id) self::run_scan((int) $id);
    }

    private static function parse_items(string $type, string $body): array {
        if ($type === 'json') {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) return [];
            $items = $decoded['items'] ?? $decoded['results'] ?? $decoded;
            if (!is_array($items)) return [];
            return array_values(array_filter(array_map(static function ($item) {
                if (!is_array($item)) return null;
                return [
                    'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                    'description' => (string) ($item['description'] ?? $item['summary'] ?? $item['content'] ?? ''),
                    'url' => (string) ($item['url'] ?? $item['link'] ?? $item['website'] ?? ''),
                    'published' => (string) ($item['published'] ?? $item['published_at'] ?? $item['date'] ?? ''),
                    'deadline' => (string) ($item['deadline'] ?? $item['closing_date'] ?? ''),
                    'company' => (string) ($item['company'] ?? $item['organization'] ?? ''),
                ];
            }, $items)));
        }
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) return [];
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) $items[] = ['title' => (string) $item->title, 'description' => (string) ($item->description ?: $item->summary), 'url' => (string) $item->link, 'published' => (string) ($item->pubDate ?: $item->published), 'deadline' => '', 'company' => ''];
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $item) {
                $link = ''; foreach ($item->link as $candidate) if ((string) $candidate['href']) { $link = (string) $candidate['href']; break; }
                $items[] = ['title' => (string) $item->title, 'description' => (string) ($item->summary ?: $item->content), 'url' => $link, 'published' => (string) ($item->published ?: $item->updated), 'deadline' => '', 'company' => ''];
            }
        }
        return $items;
    }

    private static function keywords(array $config): array {
        $default = [
            'ai video', 'motion graphics', 'video editing', 'animation', 'cgi', 'graphic design', 'brand identity', 'social media', 'content production', 'corporate presentation', 'website design', 'web development', 'outsourced creative', 'white-label',
            'فيديو بالذكاء الاصطناعي', 'موشن غرافيك', 'مونتاج', 'رسوم متحركة', 'تصميم جرافيك', 'هوية بصرية', 'إدارة وسائل التواصل', 'إنتاج محتوى', 'عرض تقديمي', 'تصميم مواقع', 'فريق إبداعي',
        ];
        $custom = array_values(array_filter(array_map('sanitize_text_field', (array) ($config['keywords'] ?? []))));
        return array_values(array_unique(array_merge($default, $custom)));
    }

    private static function analyse(array $item, array $config): array {
        $text = strtolower(trim($item['title'] . ' ' . $item['description'])); $reasons = []; $services = [];
        $configured_keywords = self::keywords($config); $keyword_match = false; foreach ($configured_keywords as $keyword) if ($keyword && strpos($text, strtolower($keyword)) !== false) { $keyword_match = true; break; }
        if (!$keyword_match) return ['reject' => 'No configured Arabic or English keyword match.'];
        $service_map = ['ai video' => 'AI video', 'motion graphics' => 'Motion graphics', 'video editing' => 'Video editing', 'animation' => '2D/3D animation', 'cgi' => 'CGI', 'graphic design' => 'Graphic design', 'brand identity' => 'Brand identity', 'social media' => 'Social media management', 'content production' => 'Social media content production', 'corporate presentation' => 'Corporate presentations', 'website design' => 'Website design and development', 'web development' => 'Website design and development', 'outsourced creative' => 'Dedicated outsourced creative team', 'white-label' => 'White-label creative production', 'فيديو بالذكاء الاصطناعي' => 'AI video', 'موشن غرافيك' => 'Motion graphics', 'مونتاج' => 'Video editing', 'رسوم متحركة' => '2D/3D animation', 'تصميم جرافيك' => 'Graphic design', 'هوية بصرية' => 'Brand identity', 'إدارة وسائل التواصل' => 'Social media management', 'إنتاج محتوى' => 'Social media content production', 'عرض تقديمي' => 'Corporate presentations', 'تصميم مواقع' => 'Website design and development'];
        foreach ($service_map as $needle => $service) if (strpos($text, $needle) !== false) $services[] = $service;
        $services = array_values(array_unique($services)); if ($services) $reasons[] = 'Service match: ' . implode(', ', $services);
        $excluded = ['government', 'ministry', 'municipality', 'public tender', 'مناقصة حكومية', 'وزارة', 'بلدية', 'مناقصة عامة'];
        foreach ($excluded as $needle) if (strpos($text, $needle) !== false) return ['reject' => 'Public-sector or tender language detected.'];
        if (!$services) return ['reject' => 'No configured Vibes service match.'];
        $demand = ['request for proposal', 'rfp', 'seeking agency', 'creative partner', 'production partner', 'external agency', 'freelance project', 'supplier', 'outsourc', 'مطلوب وكالة', 'شريك إنتاج', 'خدمات إبداعية'];
        $verified = false; foreach ($demand as $needle) if (strpos($text, $needle) !== false) { $verified = true; break; }
        $score = $verified ? 50 : 20; if ($verified) $reasons[] = 'Explicit external purchasing intent found.'; else $reasons[] = 'Relevant announcement without confirmed outsourcing request.';
        if (!empty($item['url'])) { $score += 15; $reasons[] = 'Original evidence URL available.'; }
        $country = strtolower((string) ($config['country'] ?? 'saudi arabia')); if (strpos($text, 'saudi') !== false || strpos($text, 'السعود') !== false) { $score += 15; $reasons[] = 'Saudi-market signal found.'; } elseif ($country !== 'all') $reasons[] = 'Country not confirmed in source.';
        $published = strtotime((string) ($item['published'] ?? '')); if ($published && $published >= strtotime('-45 days')) { $score += 10; $reasons[] = 'Recent publication date.'; }
        $deadline = ''; if (!empty($item['deadline'])) { $deadline_ts = strtotime($item['deadline']); if ($deadline_ts && $deadline_ts < current_time('timestamp')) return ['reject' => 'Expired deadline.']; $deadline = $deadline_ts ? gmdate('Y-m-d', $deadline_ts) : ''; }
        $score = min(100, $score + (!empty($item['company']) ? 5 : 0));
        return ['type' => $verified ? 'verified_demand' : 'potential_opportunity', 'services' => $services, 'score' => $score, 'reasons' => $reasons, 'deadline' => $deadline, 'publication_date' => $published ? gmdate('Y-m-d', $published) : null];
    }

    private static function verify_evidence(string $url): array {
        $response = wp_safe_remote_request($url, ['method' => 'HEAD', 'timeout' => 10, 'redirection' => 3]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 400) {
            $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'limit_response_size' => 200000]);
        }
        if (is_wp_error($response)) return ['status' => 'unverified', 'excerpt' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response); $text = wp_strip_all_tags(wp_remote_retrieve_body($response));
        return ['status' => ($code >= 200 && $code < 400) ? 'verified' : 'unverified', 'excerpt' => substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 1000)];
    }

    public static function run_scan(int $source_id = 0) {
        global $wpdb;
        $where = $source_id ? $wpdb->prepare(' WHERE id=%d', $source_id) : ' WHERE active=1';
        $sources = $wpdb->get_results('SELECT * FROM ' . VPM_Permissions::table('crm_sources') . $where, ARRAY_A);
        if (!$sources) return new WP_Error('source_missing', 'No active Lead Hunter source is configured.', ['status' => 404]);
        $results = [];
        foreach ($sources as $source) {
            $now = current_time('mysql'); $wpdb->insert(VPM_Permissions::table('crm_scans'), ['source_id' => (int) $source['id'], 'status' => 'running', 'started_at' => $now, 'created_at' => $now]); $scan_id = (int) $wpdb->insert_id;
            $response = wp_safe_remote_get(esc_url_raw($source['endpoint_url']), ['timeout' => 20, 'redirection' => 3, 'headers' => ['Accept' => 'application/rss+xml, application/atom+xml, application/json, text/xml']]);
            if (is_wp_error($response)) { self::finish_source_error($source, $scan_id, $response->get_error_message()); $results[] = ['source' => $source['name'], 'error' => $response->get_error_message()]; continue; }
            $code = wp_remote_retrieve_response_code($response); $body = wp_remote_retrieve_body($response); if ($code < 200 || $code >= 300 || !$body) { $message = 'Source returned HTTP ' . $code; self::finish_source_error($source, $scan_id, $message); $results[] = ['source' => $source['name'], 'error' => $message]; continue; }
            $config = json_decode($source['config_json'] ?: '{}', true); if (!is_array($config)) $config = [];
            $items = self::parse_items($source['source_type'], $body); $accepted = 0; $rejected = 0;
            foreach ($items as $item) {
                if (!$item['url'] || !$item['title']) { $rejected++; continue; }
                $analysis = self::analyse($item, $config); if (!empty($analysis['reject'])) { $rejected++; continue; }
                $evidence = self::verify_evidence(esc_url_raw($item['url'], ['https'])); if ($evidence['status'] !== 'verified') { $rejected++; continue; }
                $company = sanitize_text_field($item['company'] ?: preg_replace('/\s+/', ' ', $item['title'])); $key = substr(hash('sha256', strtolower($company . '|' . $item['url'])), 0, 64);
                if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . VPM_Permissions::table('crm_leads') . ' WHERE normalized_key=%s', $key))) { $rejected++; continue; }
                $now2 = current_time('mysql'); $wpdb->insert(VPM_Permissions::table('crm_leads'), ['lead_type' => $analysis['type'], 'company' => $company, 'country' => sanitize_text_field($config['country'] ?? 'Saudi Arabia'), 'services_json' => wp_json_encode($analysis['services']), 'opportunity_description' => sanitize_textarea_field(wp_strip_all_tags($item['description'])), 'lead_source' => sanitize_text_field($source['name']), 'evidence_url' => esc_url_raw($item['url'], ['https']), 'publication_date' => $analysis['publication_date'], 'deadline' => $analysis['deadline'] ?: null, 'lead_score' => $analysis['score'], 'score_reasons_json' => wp_json_encode($analysis['reasons']), 'stage_key' => 'new_lead', 'review_status' => 'pending', 'normalized_key' => $key, 'created_by' => 0, 'created_at' => $now2, 'updated_at' => $now2]);
                $lead_id = (int) $wpdb->insert_id; $wpdb->insert(VPM_Permissions::table('crm_evidence'), ['lead_id' => $lead_id, 'evidence_url' => esc_url_raw($item['url'], ['https']), 'excerpt' => $evidence['excerpt'], 'published_at' => $analysis['publication_date'], 'verified_at' => current_time('mysql'), 'verification_status' => 'verified', 'source_hash' => hash('sha256', strtolower($item['url'])), 'created_at' => $now2]);
                if ($analysis['type'] === 'verified_demand' && (float) $analysis['score'] >= 70) {
                    $admins = get_users(['role' => 'administrator', 'fields' => 'ID']); foreach ($admins as $admin_id) $wpdb->insert(VPM_Permissions::table('notifications'), ['user_id' => $admin_id, 'type' => 'lead_hunter', 'title' => 'High-quality lead hunter opportunity', 'body' => $company . ' · score ' . $analysis['score'], 'object_type' => 'lead', 'object_id' => $lead_id, 'created_at' => $now2]);
                }
                $accepted++;
            }
            $wpdb->update(VPM_Permissions::table('crm_sources'), ['last_success_at' => current_time('mysql'), 'last_error' => '', 'updated_at' => current_time('mysql')], ['id' => (int) $source['id']]); $wpdb->update(VPM_Permissions::table('crm_scans'), ['status' => 'completed', 'finished_at' => current_time('mysql'), 'found_count' => count($items), 'accepted_count' => $accepted], ['id' => $scan_id]);
            $results[] = ['source' => $source['name'], 'found' => count($items), 'accepted' => $accepted, 'rejected' => $rejected];
        }
        return ['scans' => $results];
    }


    public static function google_status(): array {
        $key = self::places_api_key();
        return ['configured' => (bool) $key, 'provider' => 'Google Places API (New)', 'serverSide' => true, 'fieldMask' => 'curated business fields only'];
    }

    private static function places_api_key(): string {
        return trim((string) get_option('vpm_google_places_api_key', ''));
    }

    private static function places_query(array $input): string {
        $values = [];
        foreach (['keywords', 'cities', 'countries', 'industry'] as $field) {
            $value = $input[$field] ?? '';
            if (is_array($value)) $values = array_merge($values, $value);
            elseif (is_string($value) && trim($value) !== '') $values = array_merge($values, preg_split('/[,\n]+/', $value));
        }
        $values = array_values(array_unique(array_filter(array_map('sanitize_text_field', $values))));
        return implode(', ', array_slice($values, 0, 12));
    }

    private static function places_services(string $text): array {
        $text = strtolower($text); $map = [
            'hotel' => 'Social Media Management', 'resort' => 'Social Media Management', 'hospitality' => 'Social Media Management',
            'real estate' => 'Branding', 'property' => 'Branding', 'developer' => 'Website Design & Development',
            'technology' => 'Website Design & Development', 'software' => 'Website Design & Development', 'computer' => 'Website Design & Development',
            'health' => 'Graphic Design', 'clinic' => 'Graphic Design', 'hospital' => 'Video Editing', 'medical' => 'Video Editing',
            'construction' => 'Presentations', 'contractor' => 'Presentations', 'engineering' => 'Presentations',
            'restaurant' => 'Social Media Management', 'retail' => 'Digital Marketing', 'shopping' => 'Digital Marketing',
            'ecommerce' => 'Digital Marketing', 'education' => 'Video Editing', 'school' => 'Video Editing',
        ];
        $out = []; foreach ($map as $needle => $service) if (strpos($text, $needle) !== false) $out[] = $service;
        return array_values(array_unique($out));
    }

    private static function places_address(array $components): array {
        $city = ''; $country = '';
        foreach ($components as $component) {
            $types = (array) ($component['types'] ?? []); $name = (string) ($component['longText'] ?? $component['long_name'] ?? '');
            if (in_array('locality', $types, true) || (!$city && in_array('administrative_area_level_2', $types, true))) $city = $name;
            if (in_array('country', $types, true)) $country = $name;
        }
        return ['city' => $city, 'country' => $country];
    }

    private static function places_duplicate(array $place, string $company, string $website, string $phone): ?array {
        global $wpdb;
        $where = []; $args = [];
        if ($company !== '') { $where[] = 'company=%s'; $args[] = $company; }
        if ($website !== '') { $where[] = 'website=%s'; $args[] = $website; }
        if ($phone !== '') { $where[] = 'contact_phone=%s'; $args[] = $phone; }
        if (!$where) return null;
        $sql = 'SELECT id,company,website,contact_phone,country,city FROM ' . VPM_Permissions::table('crm_leads') . ' WHERE ' . implode(' OR ', $where) . ' ORDER BY id ASC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
        return $row ?: null;
    }

    public static function google_places_search(array $input) {
        global $wpdb;
        $key = self::places_api_key();
        if (!$key) return new WP_Error('google_places_not_configured', 'Google Places is not configured on the server yet.', ['status' => 503]);
        $query = self::places_query($input);
        if ($query === '') return new WP_Error('missing_search', 'Add at least one keyword, city, country, or industry.', ['status' => 400]);
        $limit = min(20, max(1, absint($input['maxResults'] ?? 20)));
        $response = wp_safe_remote_post('https://places.googleapis.com/v1/places:searchText', [
            'timeout' => 25, 'redirection' => 2,
            'headers' => ['Content-Type' => 'application/json', 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.websiteUri,places.nationalPhoneNumber,places.primaryType,places.primaryTypeDisplayName,places.googleMapsUri'],
            'body' => wp_json_encode(['textQuery' => $query, 'pageSize' => $limit, 'languageCode' => 'en']),
        ]);
        if (is_wp_error($response)) return new WP_Error('google_places_request_failed', $response->get_error_message(), ['status' => 502]);
        $code = wp_remote_retrieve_response_code($response); $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = is_array($body) && !empty($body['error']['message']) ? sanitize_text_field($body['error']['message']) : 'Google Places returned HTTP ' . $code . '.';
            return new WP_Error('google_places_api_error', $message, ['status' => $code >= 400 && $code < 500 ? $code : 502]);
        }
        $rows = []; $new_count = 0; $duplicate_count = 0; $places = is_array($body['places'] ?? null) ? $body['places'] : [];
        foreach ($places as $place) {
            $company = sanitize_text_field((string) ($place['displayName']['text'] ?? $place['displayName'] ?? '')); if (!$company) continue;
            $address = sanitize_text_field((string) ($place['formattedAddress'] ?? '')); $parts = self::places_address((array) ($place['addressComponents'] ?? []));
            $city = sanitize_text_field($parts['city']); $country = sanitize_text_field($parts['country']); $website = esc_url_raw((string) ($place['websiteUri'] ?? ''), ['https']); $phone = sanitize_text_field((string) ($place['nationalPhoneNumber'] ?? ''));
            $industry = sanitize_text_field((string) ($place['primaryTypeDisplayName']['text'] ?? $place['primaryType'] ?? 'Business')); $services = self::places_services(strtolower($company . ' ' . $industry . ' ' . $query));
            $score = 25; $reasons = ['Google Places public listing matched the confirmed search.']; if ($services) { $score += 25; $reasons[] = 'Relevant Vibes service recommendations were derived from the business category.'; } if ($website) { $score += 15; $reasons[] = 'Public website available.'; } if ($phone) { $score += 10; $reasons[] = 'Public business phone available.'; } if ($city) { $score += 10; $reasons[] = 'City information available.'; } $score = min(100, $score);
            $duplicate = self::places_duplicate($place, $company, $website, $phone); $id = $duplicate ? (int) $duplicate['id'] : 0;
            if (!$duplicate) {
                $now = current_time('mysql'); $place_id = sanitize_text_field((string) ($place['id'] ?? '')); $normalized = substr(hash('sha256', 'google-places|' . ($place_id ?: strtolower($company . '|' . $website . '|' . $phone))), 0, 64);
                $wpdb->insert(VPM_Permissions::table('crm_leads'), ['lead_type' => 'potential_opportunity', 'company' => $company, 'industry' => $industry, 'country' => $country, 'city' => $city, 'website' => $website, 'contact_phone' => $phone, 'services_json' => wp_json_encode($services), 'opportunity_description' => 'Discovered from a permitted Google Places public business listing. Review before outreach.', 'lead_source' => 'Google Places', 'evidence_url' => esc_url_raw((string) ($place['googleMapsUri'] ?? ''), ['https']), 'lead_score' => $score, 'score_reasons_json' => wp_json_encode($reasons), 'stage_key' => 'new_lead', 'review_status' => 'pending', 'normalized_key' => $normalized, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
                $id = (int) $wpdb->insert_id; $new_count++;
            } else $duplicate_count++;
            $rows[] = ['id' => $id, 'company' => $company, 'industry' => $industry, 'country' => $country, 'city' => $city, 'website' => $website, 'contact_phone' => $phone, 'services' => $services, 'lead_score' => $score, 'score_reasons' => $reasons, 'lead_source' => 'Google Places', 'evidence_url' => (string) ($place['googleMapsUri'] ?? ''), 'review_status' => $duplicate ? 'existing' : 'pending', 'duplicate' => (bool) $duplicate, 'address' => $address];
        }
        return ['provider' => 'Google Places API (New)', 'query' => $query, 'rows' => $rows, 'found' => count($rows), 'new' => $new_count, 'duplicates' => $duplicate_count, 'apiUsage' => ['requests' => 1, 'fieldMask' => 'curated business fields'], 'estimatedCostUsd' => null];
    }

    private static function finish_source_error(array $source, int $scan_id, string $message): void {
        global $wpdb; $wpdb->update(VPM_Permissions::table('crm_sources'), ['last_error' => sanitize_textarea_field($message), 'updated_at' => current_time('mysql')], ['id' => (int) $source['id']]); $wpdb->update(VPM_Permissions::table('crm_scans'), ['status' => 'failed', 'finished_at' => current_time('mysql'), 'error' => sanitize_textarea_field($message)], ['id' => $scan_id]);
    }
}

defined('ABSPATH') || exit;

final class VPM_Cron {
    public static function boot(): void {
        add_action('vpm_daily_lead_scan', [self::class, 'daily_scan']);
    }

    public static function daily_scan(): void {
        global $wpdb;
        $ids = $wpdb->get_col('SELECT id FROM ' . VPM_Permissions::table('crm_sources') . ' WHERE active=1 ORDER BY id ASC');
        foreach ($ids as $id) self::run_scan((int) $id);
    }

    private static function parse_items(string $type, string $body): array {
        if ($type === 'json') {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) return [];
            $items = $decoded['items'] ?? $decoded['results'] ?? $decoded;
            if (!is_array($items)) return [];
            return array_values(array_filter(array_map(static function ($item) {
                if (!is_array($item)) return null;
                return [
                    'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                    'description' => (string) ($item['description'] ?? $item['summary'] ?? $item['content'] ?? ''),
                    'url' => (string) ($item['url'] ?? $item['link'] ?? $item['website'] ?? ''),
                    'published' => (string) ($item['published'] ?? $item['published_at'] ?? $item['date'] ?? ''),
                    'deadline' => (string) ($item['deadline'] ?? $item['closing_date'] ?? ''),
                    'company' => (string) ($item['company'] ?? $item['organization'] ?? ''),
                ];
            }, $items)));
        }
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) return [];
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) $items[] = ['title' => (string) $item->title, 'description' => (string) ($item->description ?: $item->summary), 'url' => (string) $item->link, 'published' => (string) ($item->pubDate ?: $item->published), 'deadline' => '', 'company' => ''];
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $item) {
                $link = ''; foreach ($item->link as $candidate) if ((string) $candidate['href']) { $link = (string) $candidate['href']; break; }
                $items[] = ['title' => (string) $item->title, 'description' => (string) ($item->summary ?: $item->content), 'url' => $link, 'published' => (string) ($item->published ?: $item->updated), 'deadline' => '', 'company' => ''];
            }
        }
        return $items;
    }

    private static function keywords(array $config): array {
        $default = [
            'ai video', 'motion graphics', 'video editing', 'animation', 'cgi', 'graphic design', 'brand identity', 'social media', 'content production', 'corporate presentation', 'website design', 'web development', 'outsourced creative', 'white-label',
            'فيديو بالذكاء الاصطناعي', 'موشن غرافيك', 'مونتاج', 'رسوم متحركة', 'تصميم جرافيك', 'هوية بصرية', 'إدارة وسائل التواصل', 'إنتاج محتوى', 'عرض تقديمي', 'تصميم مواقع', 'فريق إبداعي',
        ];
        $custom = array_values(array_filter(array_map('sanitize_text_field', (array) ($config['keywords'] ?? []))));
        return array_values(array_unique(array_merge($default, $custom)));
    }

    private static function analyse(array $item, array $config): array {
        $text = strtolower(trim($item['title'] . ' ' . $item['description'])); $reasons = []; $services = [];
        $configured_keywords = self::keywords($config); $keyword_match = false; foreach ($configured_keywords as $keyword) if ($keyword && strpos($text, strtolower($keyword)) !== false) { $keyword_match = true; break; }
        if (!$keyword_match) return ['reject' => 'No configured Arabic or English keyword match.'];
        $service_map = ['ai video' => 'AI video', 'motion graphics' => 'Motion graphics', 'video editing' => 'Video editing', 'animation' => '2D/3D animation', 'cgi' => 'CGI', 'graphic design' => 'Graphic design', 'brand identity' => 'Brand identity', 'social media' => 'Social media management', 'content production' => 'Social media content production', 'corporate presentation' => 'Corporate presentations', 'website design' => 'Website design and development', 'web development' => 'Website design and development', 'outsourced creative' => 'Dedicated outsourced creative team', 'white-label' => 'White-label creative production', 'فيديو بالذكاء الاصطناعي' => 'AI video', 'موشن غرافيك' => 'Motion graphics', 'مونتاج' => 'Video editing', 'رسوم متحركة' => '2D/3D animation', 'تصميم جرافيك' => 'Graphic design', 'هوية بصرية' => 'Brand identity', 'إدارة وسائل التواصل' => 'Social media management', 'إنتاج محتوى' => 'Social media content production', 'عرض تقديمي' => 'Corporate presentations', 'تصميم مواقع' => 'Website design and development'];
        foreach ($service_map as $needle => $service) if (strpos($text, $needle) !== false) $services[] = $service;
        $services = array_values(array_unique($services)); if ($services) $reasons[] = 'Service match: ' . implode(', ', $services);
        $excluded = ['government', 'ministry', 'municipality', 'public tender', 'مناقصة حكومية', 'وزارة', 'بلدية', 'مناقصة عامة'];
        foreach ($excluded as $needle) if (strpos($text, $needle) !== false) return ['reject' => 'Public-sector or tender language detected.'];
        if (!$services) return ['reject' => 'No configured Vibes service match.'];
        $demand = ['request for proposal', 'rfp', 'seeking agency', 'creative partner', 'production partner', 'external agency', 'freelance project', 'supplier', 'outsourc', 'مطلوب وكالة', 'شريك إنتاج', 'خدمات إبداعية'];
        $verified = false; foreach ($demand as $needle) if (strpos($text, $needle) !== false) { $verified = true; break; }
        $score = $verified ? 50 : 20; if ($verified) $reasons[] = 'Explicit external purchasing intent found.'; else $reasons[] = 'Relevant announcement without confirmed outsourcing request.';
        if (!empty($item['url'])) { $score += 15; $reasons[] = 'Original evidence URL available.'; }
        $country = strtolower((string) ($config['country'] ?? 'saudi arabia')); if (strpos($text, 'saudi') !== false || strpos($text, 'السعود') !== false) { $score += 15; $reasons[] = 'Saudi-market signal found.'; } elseif ($country !== 'all') $reasons[] = 'Country not confirmed in source.';
        $published = strtotime((string) ($item['published'] ?? '')); if ($published && $published >= strtotime('-45 days')) { $score += 10; $reasons[] = 'Recent publication date.'; }
        $deadline = ''; if (!empty($item['deadline'])) { $deadline_ts = strtotime($item['deadline']); if ($deadline_ts && $deadline_ts < current_time('timestamp')) return ['reject' => 'Expired deadline.']; $deadline = $deadline_ts ? gmdate('Y-m-d', $deadline_ts) : ''; }
        $score = min(100, $score + (!empty($item['company']) ? 5 : 0));
        return ['type' => $verified ? 'verified_demand' : 'potential_opportunity', 'services' => $services, 'score' => $score, 'reasons' => $reasons, 'deadline' => $deadline, 'publication_date' => $published ? gmdate('Y-m-d', $published) : null];
    }

    private static function verify_evidence(string $url): array {
        $response = wp_safe_remote_request($url, ['method' => 'HEAD', 'timeout' => 10, 'redirection' => 3]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 400) {
            $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'limit_response_size' => 200000]);
        }
        if (is_wp_error($response)) return ['status' => 'unverified', 'excerpt' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response); $text = wp_strip_all_tags(wp_remote_retrieve_body($response));
        return ['status' => ($code >= 200 && $code < 400) ? 'verified' : 'unverified', 'excerpt' => substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 1000)];
    }

    public static function run_scan(int $source_id = 0) {
        global $wpdb;
        $where = $source_id ? $wpdb->prepare(' WHERE id=%d', $source_id) : ' WHERE active=1';
        $sources = $wpdb->get_results('SELECT * FROM ' . VPM_Permissions::table('crm_sources') . $where, ARRAY_A);
        if (!$sources) return new WP_Error('source_missing', 'No active Lead Hunter source is configured.', ['status' => 404]);
        $results = [];
        foreach ($sources as $source) {
            $now = current_time('mysql'); $wpdb->insert(VPM_Permissions::table('crm_scans'), ['source_id' => (int) $source['id'], 'status' => 'running', 'started_at' => $now, 'created_at' => $now]); $scan_id = (int) $wpdb->insert_id;
            $response = wp_safe_remote_get(esc_url_raw($source['endpoint_url']), ['timeout' => 20, 'redirection' => 3, 'headers' => ['Accept' => 'application/rss+xml, application/atom+xml, application/json, text/xml']]);
            if (is_wp_error($response)) { self::finish_source_error($source, $scan_id, $response->get_error_message()); $results[] = ['source' => $source['name'], 'error' => $response->get_error_message()]; continue; }
            $code = wp_remote_retrieve_response_code($response); $body = wp_remote_retrieve_body($response); if ($code < 200 || $code >= 300 || !$body) { $message = 'Source returned HTTP ' . $code; self::finish_source_error($source, $scan_id, $message); $results[] = ['source' => $source['name'], 'error' => $message]; continue; }
            $config = json_decode($source['config_json'] ?: '{}', true); if (!is_array($config)) $config = [];
            $items = self::parse_items($source['source_type'], $body); $accepted = 0; $rejected = 0;
            foreach ($items as $item) {
                if (!$item['url'] || !$item['title']) { $rejected++; continue; }
                $analysis = self::analyse($item, $config); if (!empty($analysis['reject'])) { $rejected++; continue; }
                $evidence = self::verify_evidence(esc_url_raw($item['url'], ['https'])); if ($evidence['status'] !== 'verified') { $rejected++; continue; }
                $company = sanitize_text_field($item['company'] ?: preg_replace('/\s+/', ' ', $item['title'])); $key = substr(hash('sha256', strtolower($company . '|' . $item['url'])), 0, 64);
                if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . VPM_Permissions::table('crm_leads') . ' WHERE normalized_key=%s', $key))) { $rejected++; continue; }
                $now2 = current_time('mysql'); $wpdb->insert(VPM_Permissions::table('crm_leads'), ['lead_type' => $analysis['type'], 'company' => $company, 'country' => sanitize_text_field($config['country'] ?? 'Saudi Arabia'), 'services_json' => wp_json_encode($analysis['services']), 'opportunity_description' => sanitize_textarea_field(wp_strip_all_tags($item['description'])), 'lead_source' => sanitize_text_field($source['name']), 'evidence_url' => esc_url_raw($item['url'], ['https']), 'publication_date' => $analysis['publication_date'], 'deadline' => $analysis['deadline'] ?: null, 'lead_score' => $analysis['score'], 'score_reasons_json' => wp_json_encode($analysis['reasons']), 'stage_key' => 'new_lead', 'review_status' => 'pending', 'normalized_key' => $key, 'created_by' => 0, 'created_at' => $now2, 'updated_at' => $now2]);
                $lead_id = (int) $wpdb->insert_id; $wpdb->insert(VPM_Permissions::table('crm_evidence'), ['lead_id' => $lead_id, 'evidence_url' => esc_url_raw($item['url'], ['https']), 'excerpt' => $evidence['excerpt'], 'published_at' => $analysis['publication_date'], 'verified_at' => current_time('mysql'), 'verification_status' => 'verified', 'source_hash' => hash('sha256', strtolower($item['url'])), 'created_at' => $now2]);
                if ($analysis['type'] === 'verified_demand' && (float) $analysis['score'] >= 70) {
                    $admins = get_users(['role' => 'administrator', 'fields' => 'ID']); foreach ($admins as $admin_id) $wpdb->insert(VPM_Permissions::table('notifications'), ['user_id' => $admin_id, 'type' => 'lead_hunter', 'title' => 'High-quality lead hunter opportunity', 'body' => $company . ' · score ' . $analysis['score'], 'object_type' => 'lead', 'object_id' => $lead_id, 'created_at' => $now2]);
                }
                $accepted++;
            }
            $wpdb->update(VPM_Permissions::table('crm_sources'), ['last_success_at' => current_time('mysql'), 'last_error' => '', 'updated_at' => current_time('mysql')], ['id' => (int) $source['id']]); $wpdb->update(VPM_Permissions::table('crm_scans'), ['status' => 'completed', 'finished_at' => current_time('mysql'), 'found_count' => count($items), 'accepted_count' => $accepted], ['id' => $scan_id]);
            $results[] = ['source' => $source['name'], 'found' => count($items), 'accepted' => $accepted, 'rejected' => $rejected];
        }
        return ['scans' => $results];
    }

    private static function finish_source_error(array $source, int $scan_id, string $message): void {
        global $wpdb; $wpdb->update(VPM_Permissions::table('crm_sources'), ['last_error' => sanitize_textarea_field($message), 'updated_at' => current_time('mysql')], ['id' => (int) $source['id']]); $wpdb->update(VPM_Permissions::table('crm_scans'), ['status' => 'failed', 'finished_at' => current_time('mysql'), 'error' => sanitize_textarea_field($message)], ['id' => $scan_id]);
    }
}

