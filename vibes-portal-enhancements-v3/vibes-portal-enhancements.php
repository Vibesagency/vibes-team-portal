<?php
/**
 * Plugin Name: Vibes Quotation Cycles
 * Description: Adds long-term quotation master/monthly drafts and replaceable Google Drive quotation references to the Vibes Team Portal.
 * Version: 1.0.0
 * Author: Vibes Agency
 */

if (!defined('ABSPATH')) exit;

function vce_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'vtp_quotations';
}

function vce_ensure_schema(): void {
    global $wpdb;
    $table = vce_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;
    $columns = [
        'contract_type' => "varchar(20) NOT NULL DEFAULT 'one_time'",
        'contract_months' => 'smallint unsigned NOT NULL DEFAULT 1',
        'contract_start' => 'date DEFAULT NULL',
        'contract_end' => 'date DEFAULT NULL',
        'parent_quotation_id' => 'bigint unsigned DEFAULT NULL',
        'period_index' => 'smallint unsigned NOT NULL DEFAULT 0',
        'period_start' => 'date DEFAULT NULL',
        'period_end' => 'date DEFAULT NULL',
        'template_key' => "varchar(30) NOT NULL DEFAULT 'one_time'",
        'drive_folder_url' => 'text DEFAULT NULL',
        'drive_file_url' => 'text DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $name))) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
        }
    }
}

register_activation_hook(__FILE__, 'vce_ensure_schema');
add_action('plugins_loaded', 'vce_ensure_schema', 2);

function vce_date(string $date): bool {
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function vce_reference(): string {
    return 'VQ-' . gmdate('Ymd') . '-' . strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 8));
}

function vce_templates(): array {
    $defaults = [
        'one_time' => ['label' => 'One-time project quotation', 'url' => 'https://drive.google.com/file/d/1OgUtWtiKgGC-J2XRgzB2D8eSx7AwYWZd/view?usp=drivesdk'],
        'six_months_up_to_7' => ['label' => '6-month quotation · up to 7 services', 'url' => 'https://drive.google.com/file/d/1HO2cwpKlVD30W0_YPHHfqygO35f0NuPN/view?usp=drivesdk'],
        'six_months_more_than_7' => ['label' => '6-month quotation · more than 7 services', 'url' => 'https://drive.google.com/file/d/1dAURkQGtD-WPcWehkL8q75dKYpGsdhVC/view?usp=drivesdk'],
        'one_year_up_to_7' => ['label' => '1-year quotation · up to 7 services', 'url' => 'https://drive.google.com/file/d/1741QmzMXiVrxgBDaFs_Q1W3jwfZ8FHyR/view?usp=drivesdk'],
        'one_year_more_than_7' => ['label' => '1-year quotation · more than 7 services', 'url' => 'https://drive.google.com/file/d/1xsufa8Vd9rzF8AdzfxxeJq-Pym-xWrSL/view?usp=drivesdk'],
    ];
    $saved = get_option('vce_quotation_templates', []);
    if (!is_array($saved)) $saved = [];
    $out = [];
    foreach ($defaults as $key => $default) {
        $item = array_merge($default, is_array($saved[$key] ?? null) ? $saved[$key] : []);
        $out[$key] = ['key' => $key, 'label' => sanitize_text_field($item['label']), 'url' => esc_url_raw($item['url'])];
    }
    return $out;
}

function vce_can_manage(): bool {
    return current_user_can('vtp_manage_sales') || current_user_can('manage_options');
}

function vce_routes(): void {
    register_rest_route('vibes/v1', '/quotation-template-links', [
        ['methods' => 'GET', 'callback' => static function () { return rest_ensure_response(['admin' => vce_can_manage(), 'templates' => array_values(vce_templates())]); }, 'permission_callback' => static function () { return is_user_logged_in(); }],
        ['methods' => 'POST', 'callback' => 'vce_save_template', 'permission_callback' => 'vce_can_manage'],
    ]);
    register_rest_route('vibes/v1', '/quotations/(?P<id>\d+)/drive', [
        'methods' => 'PATCH', 'callback' => 'vce_save_quotation_drive', 'permission_callback' => 'vce_can_manage',
    ]);
}
add_action('rest_api_init', 'vce_routes');

function vce_save_template(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $key = sanitize_key((string) ($data['key'] ?? ''));
    if (!in_array($key, ['one_time', 'six_months_up_to_7', 'six_months_more_than_7', 'one_year_up_to_7', 'one_year_more_than_7'], true)) return new WP_Error('invalid_template', 'Choose a valid quotation template.', ['status' => 400]);
    $url = esc_url_raw((string) ($data['url'] ?? ''));
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (!$url || !wp_http_validate_url($url) || !in_array($host, ['drive.google.com', 'docs.google.com'], true)) return new WP_Error('invalid_template_url', 'Use a Google Drive or Google Docs link.', ['status' => 400]);
    $saved = get_option('vce_quotation_templates', []);
    if (!is_array($saved)) $saved = [];
    $current = vce_templates();
    $saved[$key] = ['label' => $current[$key]['label'], 'url' => $url];
    update_option('vce_quotation_templates', $saved, false);
    return rest_ensure_response(['admin' => true, 'templates' => array_values(vce_templates())]);
}

function vce_save_quotation_drive(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request['id'];
    if (!$id || !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . vce_table() . ' WHERE id=%d', $id))) {
        return new WP_Error('not_found', 'Quotation not found.', ['status' => 404]);
    }
    $folder = esc_url_raw((string) ($request->get_param('folderUrl') ?? ''));
    $file = esc_url_raw((string) ($request->get_param('fileUrl') ?? ''));
    foreach ([$folder, $file] as $url) {
        if ($url) {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if (!wp_http_validate_url($url) || !in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
                return new WP_Error('invalid_drive_url', 'Use a Google Drive or Google Docs link.', ['status' => 400]);
            }
        }
    }
    $root_id = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(parent_quotation_id, id) FROM ' . vce_table() . ' WHERE id=%d', $id));
    $now = current_time('mysql');
    if ($root_id) {
        $root_number = (string) $wpdb->get_var($wpdb->prepare('SELECT quote_number FROM ' . vce_table() . ' WHERE id=%d', $root_id));
        $wpdb->query($wpdb->prepare('UPDATE ' . vce_table() . ' SET drive_folder_url=%s, updated_at=%s WHERE id=%d OR parent_quotation_id=%d OR quote_number LIKE %s', $folder, $now, $root_id, $root_id, $root_number . '-M%'));
    }
    $wpdb->update(vce_table(), ['drive_file_url' => $file, 'updated_at' => $now], ['id' => $id]);
    return rest_ensure_response(['success' => true, 'folderUrl' => $folder, 'fileUrl' => $file]);
}

function vce_create_quotation(WP_REST_Request $request) {
    global $wpdb;
    vce_ensure_schema();
    $data = $request->get_json_params();
    if (!is_array($data)) $data = $request->get_params();
    $raw_items = $data['items'] ?? [];
    if (!is_array($raw_items) || !$raw_items) return new WP_Error('missing_items', 'Add at least one quotation item.', ['status' => 400]);
    $items = [];
    $subtotal = 0.0;
    $detected_months = 1;
    foreach ($raw_items as $raw) {
        $description = sanitize_text_field($raw['description'] ?? '');
        if (!$description) continue;
        $quantity = max(0.01, (float) ($raw['quantity'] ?? 1));
        $unit = max(0, (float) ($raw['unitPrice'] ?? 0));
        $line = round($quantity * $unit, 2);
        $term = sanitize_key((string) ($raw['term'] ?? $raw['billing'] ?? 'once'));
        $duration = max(0, min(60, (int) ($raw['durationMonths'] ?? 0)));
        $detected_months = max($detected_months, $duration);
        $rate = min(100, max(0, (float) ($raw['durationDiscount'] ?? $raw['annualDiscount'] ?? 0)));
        $items[] = [
            'label' => strtoupper(substr(sanitize_key($raw['label'] ?? ''), 0, 12)),
            'description' => $description, 'quantity' => $quantity, 'unitPrice' => round($unit, 2), 'total' => $line,
            'billing' => $term === 'once' ? 'once' : 'month', 'term' => $term,
            'durationMonths' => $duration, 'durationDiscount' => round($rate, 2), 'annualDiscount' => round($rate, 2),
            'packageName' => sanitize_text_field($raw['packageName'] ?? ''),
            'quantityScope' => 'monthly',
        ];
        $subtotal += $line;
    }
    if (!$items) return new WP_Error('missing_items', 'Add at least one valid quotation item.', ['status' => 400]);
    $name = sanitize_text_field($data['clientName'] ?? '');
    $email = sanitize_email($data['email'] ?? '');
    if (!$name || !$email) return new WP_Error('missing_fields', 'Client name and email are required.', ['status' => 400]);
    $months = max(1, min(60, (int) ($data['contractMonths'] ?? $detected_months)));
    $type = sanitize_key((string) ($data['contractType'] ?? ($months > 1 ? 'long_term' : 'one_time')));
    if ($type !== 'long_term' || $months < 2) { $type = 'one_time'; $months = 1; }
    $start = sanitize_text_field((string) ($data['contractStart'] ?? ''));
    if ($months > 1 && (!$start || !vce_date($start))) $start = current_time('Y-m-d');
    $end = sanitize_text_field((string) ($data['contractEnd'] ?? ''));
    if ($months > 1) $end = (new DateTime($start))->modify('+' . $months . ' months -1 day')->format('Y-m-d');
    $currency = in_array(($data['currency'] ?? ''), ['SAR', 'USD'], true) ? $data['currency'] : 'SAR';
    $vat_rate = min(100, max(0, (float) ($data['vatRate'] ?? 0)));
    // Contract discounts are fixed by term and apply to both the master
    // quotation and each monthly quotation generated from it.
    $contract_discount = $months === 12 ? 10.0 : ($months === 6 ? 5.0 : 0.0);
    $vat = round($subtotal * $vat_rate / 100, 2);
    $now = current_time('mysql');
    $table = vce_table();
    $master_number = vce_reference();
    $service_count = count($items);
    $variant = $service_count > 7 ? 'more_than_7' : 'up_to_7';
    $template = $months === 12 ? 'one_year_' . $variant : ($months === 6 ? 'six_months_' . $variant : 'one_time');
    // The master quotation represents the full contracted scope. The form
    // quantity is monthly, so a 2-unit service becomes 12 units on a
    // six-month master and 24 units on a one-year master. Monthly drafts keep
    // the original monthly quantity.
    $master_items = $items;
    $monthly_subtotal = $subtotal;
    $subtotal = 0.0;
    foreach ($master_items as &$master_item) {
        if ($months > 1) {
            $master_item['quantity'] = round((float) $master_item['quantity'] * $months, 2);
            $master_item['durationDiscount'] = $contract_discount;
            $master_item['annualDiscount'] = $contract_discount;
            $master_item['total'] = round((float) $master_item['quantity'] * (float) $master_item['unitPrice'] * (1 - $contract_discount / 100), 2);
            $master_item['quantityScope'] = 'contract';
        } else {
            $master_item['durationDiscount'] = 0.0;
            $master_item['annualDiscount'] = 0.0;
            $master_item['quantityScope'] = 'one_time';
        }
        $subtotal += (float) $master_item['total'];
    }
    unset($master_item);
    $vat = round($subtotal * $vat_rate / 100, 2);
    $wpdb->insert($table, [
        'quote_number' => $master_number, 'inquiry_id' => (int) ($data['inquiryId'] ?? 0) ?: null,
        'client_name' => $name, 'company' => sanitize_text_field($data['company'] ?? ''), 'email' => $email, 'phone' => sanitize_text_field($data['phone'] ?? ''),
        'currency' => $currency, 'subtotal' => round($subtotal, 2), 'vat_rate' => round($vat_rate, 2), 'vat_amount' => $vat, 'total' => round($subtotal + $vat, 2),
        'valid_until' => !empty($data['validUntil']) && vce_date($data['validUntil']) ? $data['validUntil'] : null, 'status' => 'draft',
        'terms' => sanitize_textarea_field($data['terms'] ?? ''), 'notes' => sanitize_textarea_field($data['notes'] ?? ''), 'items_json' => wp_json_encode($master_items),
        'contract_type' => $type, 'contract_months' => $months, 'contract_start' => $start ?: null, 'contract_end' => $end ?: null,
        'parent_quotation_id' => null, 'period_index' => 0, 'period_start' => null, 'period_end' => null, 'template_key' => $template,
        'drive_folder_url' => 'https://drive.google.com/drive/folders/1iDgbGsfeyjYvBc3KkSCHcYK--ks_Z_Ta', 'drive_file_url' => null,
        'approval_token' => bin2hex(random_bytes(32)), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $master_id = (int) $wpdb->insert_id;
    if (!$master_id) return new WP_Error('save_failed', 'The quotation could not be saved.', ['status' => 500]);
    $monthly_count = 0;
    if ($months > 1) {
        $base_start = new DateTime($start);
        for ($period = 1; $period <= $months; $period++) {
            $period_start = (clone $base_start)->modify('+' . ($period - 1) . ' months');
            $period_end = (clone $base_start)->modify('+' . $period . ' months -1 day');
            $period_items = []; $period_subtotal = 0.0;
            foreach ($items as $item) {
                $quantity = max(0.01, round((float) $item['quantity'], 2));
                $line = round($quantity * (float) $item['unitPrice'] * (1 - $contract_discount / 100), 2);
                $period_subtotal += $line;
                $period_items[] = array_merge($item, [
                    'quantity' => $quantity,
                    'total' => $line,
                    'durationDiscount' => $contract_discount,
                    'annualDiscount' => $contract_discount,
                    'quantityScope' => 'monthly',
                ]);
            }
            $period_vat = round($period_subtotal * $vat_rate / 100, 2);
            $wpdb->insert($table, [
                'quote_number' => $master_number . '-M' . str_pad((string) $period, 2, '0', STR_PAD_LEFT), 'inquiry_id' => (int) ($data['inquiryId'] ?? 0) ?: null,
                'client_name' => $name, 'company' => sanitize_text_field($data['company'] ?? ''), 'email' => $email, 'phone' => sanitize_text_field($data['phone'] ?? ''),
                'currency' => $currency, 'subtotal' => round($period_subtotal, 2), 'vat_rate' => round($vat_rate, 2), 'vat_amount' => $period_vat, 'total' => round($period_subtotal + $period_vat, 2),
                'valid_until' => !empty($data['validUntil']) && vce_date($data['validUntil']) ? $data['validUntil'] : null, 'status' => 'draft',
                'terms' => sanitize_textarea_field($data['terms'] ?? ''), 'notes' => trim(sanitize_textarea_field($data['notes'] ?? '') . "\nMonthly quotation {$period} of {$months} · " . $period_start->format('F Y') . '.'), 'items_json' => wp_json_encode($period_items),
                'contract_type' => 'monthly', 'contract_months' => $months, 'contract_start' => $start, 'contract_end' => $end, 'parent_quotation_id' => $master_id,
                'period_index' => $period, 'period_start' => $period_start->format('Y-m-d'), 'period_end' => $period_end->format('Y-m-d'), 'template_key' => 'one_time',
                'drive_folder_url' => 'https://drive.google.com/drive/folders/1iDgbGsfeyjYvBc3KkSCHcYK--ks_Z_Ta', 'drive_file_url' => null,
                'approval_token' => bin2hex(random_bytes(32)), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($wpdb->insert_id) $monthly_count++;
        }
    }
    if (function_exists('vibes_drive_quotation_sync')) { $drive_master = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . vce_table() . ' WHERE id=%d', $master_id), ARRAY_A); if (is_array($drive_master)) { vibes_drive_quotation_sync($drive_master); } } if (!empty($data['inquiryId'])) $wpdb->update($wpdb->prefix . 'vtp_inquiries', ['status' => 'brief_prepared', 'updated_at' => $now], ['id' => (int) $data['inquiryId']]);
    return new WP_REST_Response(['id' => $master_id, 'monthlyCount' => $monthly_count, 'contractMonths' => $months, 'templateKey' => $template], 201);
}

function vce_intercept_quotation($result, $server, $request) {
    if ($request->get_method() === 'POST' && $request->get_route() === '/vibes/v1/quotations' && is_user_logged_in() && current_user_can('vtp_manage_sales')) return vce_create_quotation($request);
    return $result;
}
add_filter('rest_pre_dispatch', 'vce_intercept_quotation', 1, 3);

add_action('wp_footer', static function () {
    echo '<script>';
    echo <<<'JS'
(function(){
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  let lastRoot=null;
  function addContractFields(){
    const form=document.querySelector('#vtp-quotation-form');
    if(!form || form.querySelector('[data-vce-contract-fields]') || form.elements.contractType) return;
    const items=form.querySelector('#vtp-quote-items');
    const anchor=items?.closest('.vtp-field');
    if(!anchor) return;
    const section=document.createElement('div'); section.className='vtp-field'; section.dataset.vceContractFields='1';
    section.innerHTML='<div class="vtp-card-head"><label>Contract and billing cycle</label><span class="vtp-admin-note">Long-term quotes create one master plus monthly drafts.</span></div><div class="vtp-form-row"><div class="vtp-field"><label>Quotation type</label><select name="contractType"><option value="one_time">One-time quotation</option><option value="long_term">Long-term monthly quotation</option></select></div><div class="vtp-field"><label>Number of months</label><input name="contractMonths" type="number" min="1" max="60" value="1" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Contract start date</label><input name="contractStart" type="date"></div><div class="vtp-field"><label>Contract end date</label><input name="contractEnd" type="date" readonly><small>Calculated automatically from the start date and number of months.</small></div></div>';
    anchor.before(section);
    const sync=()=>{ const type=form.elements.contractType?.value||'one_time'; const months=Math.max(1,Math.min(60,Number(form.elements.contractMonths?.value||1))); const start=form.elements.contractStart?.value||''; const end=form.elements.contractEnd; if(end&&start&&type==='long_term'){const d=new Date(start+'T00:00:00'); d.setMonth(d.getMonth()+months); d.setDate(d.getDate()-1); end.value=[d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');} else if(end){end.value='';} };
    section.addEventListener('input',sync); section.addEventListener('change',sync); sync();
  }
  function addQuotationGroupingStyles(){
    if(document.getElementById('vce-quotation-group-style')) return;
    const style=document.createElement('style'); style.id='vce-quotation-group-style';
    style.textContent=`
      .vce-client-groups-host{display:block!important}
      .vce-client-groups{display:grid;gap:24px}
      .vce-client-group{border:1px solid #e4d8f1;border-radius:22px;background:linear-gradient(180deg,#fff 0%,#fbf8ff 100%);padding:20px;box-shadow:0 12px 30px rgba(67,35,105,.06)}
      .vce-client-group__head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px;padding-bottom:15px;border-bottom:1px solid #eadff4}
      .vce-client-group__head h2{margin:4px 0 3px;color:#241535;font-size:1.35rem}
      .vce-client-group__head p{margin:0;color:#786a88;font-size:.9rem}
      .vce-client-group__label,.vce-master-banner,.vce-month-heading{font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#7440a1}
      .vce-master-slot{margin-bottom:18px}
      .vce-master-slot .vtp-quote-card{border:2px solid #7440a1;background:linear-gradient(135deg,#fff 0%,#faf4ff 100%);box-shadow:0 16px 34px rgba(91,45,139,.14)}
      .vce-master-slot .vtp-quote-card:before{content:'';display:block;height:5px;margin:-1px -1px 15px;border-radius:18px 18px 0 0;background:linear-gradient(90deg,#67339a,#a33fd0,#ec806d)}
      .vce-master-banner{display:inline-flex;align-items:center;gap:7px;margin:0 0 10px;padding:6px 10px;border-radius:999px;background:#eee1fb;color:#683392}
      .vce-master-banner:before{content:'✦';font-size:.85rem}
      .vce-month-heading{margin:0 0 10px;color:#8a6c9e}
      .vce-month-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px}
      .vce-month-grid .vtp-quote-card{min-width:0;background:#fff}
      @media (max-width:700px){.vce-client-group{padding:14px}.vce-client-group__head{display:block}.vce-client-group__head .vtp-chip{display:inline-flex;margin-top:10px}.vce-month-grid{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }
  function organizeQuotationCards(){
    const heading=[...document.querySelectorAll('h1,h2')].find(x=>x.textContent.trim()==='Quotations');
    if(!heading) return;
    const page=heading.closest('.vtp-page'); if(!page) return;
    const grid=page.querySelector('.vtp-grid.three'); if(!grid || grid.dataset.vceGrouped==='1') return;
    const cards=[...grid.querySelectorAll('.vtp-quote-card')]; if(!cards.length) return;
    addQuotationGroupingStyles();
    const groups=new Map();
    cards.forEach(card=>{
      const company=(card.querySelector('h3')?.textContent||'Client').trim();
      const detail=(card.querySelector('p')?.textContent||'').trim();
      const email=(detail.split('·').pop()||detail).trim().toLowerCase();
      const key=email||company.toLowerCase();
      if(!groups.has(key)) groups.set(key,{company,detail,master:null,months:[]});
      const quoteNumber=(card.querySelector('.vtp-card-head strong')?.textContent||'').trim();
      if(/-M\d{2}$/i.test(quoteNumber)){card.classList.add('vce-monthly-card');groups.get(key).months.push(card)}
      else {card.classList.add('vce-master-card');groups.get(key).master=card}
    });
    const host=document.createElement('div'); host.className='vce-client-groups';
    groups.forEach(group=>{
      const section=document.createElement('section'); section.className='vce-client-group';
      const head=document.createElement('div'); head.className='vce-client-group__head';
      head.innerHTML=`<div><div class="vce-client-group__label">CLIENT</div><h2>${esc(group.company)}</h2><p>${esc(group.detail)}</p></div><span class="vtp-chip blue">${group.months.length ? `${group.months.length} monthly drafts` : 'One quotation'}</span>`;
      section.appendChild(head);
      if(group.master){
        const slot=document.createElement('div'); slot.className='vce-master-slot';
        const banner=document.createElement('div'); banner.className='vce-master-banner'; banner.textContent='Master quotation · full contract scope'; slot.appendChild(banner); slot.appendChild(group.master); section.appendChild(slot);
      }
      if(group.months.length){
        const label=document.createElement('div'); label.className='vce-month-heading'; label.textContent='Monthly sub-quotations'; section.appendChild(label);
        const months=document.createElement('div'); months.className='vce-month-grid'; group.months.sort((a,b)=>a.textContent.localeCompare(b.textContent)); group.months.forEach(card=>months.appendChild(card)); section.appendChild(months);
      }
      host.appendChild(section);
    });
    grid.replaceWith(host); host.dataset.vceGrouped='1';
  }
  let quotationCache=null;
  async function enhanceLongTermPreview(){
    const paper=document.querySelector('.vtp-quote-paper');
    if(!paper || paper.dataset.vceLongterm==='1' || !window.VTP?.root) return;
    const number=(paper.getAttribute('aria-label')||paper.querySelector('.vtp-quote-heading strong')?.textContent||'').trim();
    if(!number) return;
    try {
      if(!quotationCache){
        const response=await fetch(window.VTP.root+'quotations',{credentials:'same-origin',headers:{'X-WP-Nonce':window.VTP.nonce}});
        if(!response.ok) return;
        quotationCache=await response.json();
      }
      const quote=(Array.isArray(quotationCache)?quotationCache:[]).find(item=>String(item.quote_number||'')===number);
      const months=Number(quote?.contract_months||0);
      if(!quote || Number(quote.period_index||0)>0 || months<2) return;
      paper.dataset.vceLongterm='1';
      const currency=String(quote.currency||'SAR');
      const monthly=(Number(quote.total||0)/months).toFixed(2);
      const dateLabel=value=>{if(!value)return '—';const d=new Date(value+'T00:00:00');return Number.isNaN(d.getTime())?'—':d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});};
      const details=document.createElement('section'); details.className='vce-longterm-details';
      details.innerHTML=`<div class="vce-longterm-summary"><div><small>CONTRACT TERM</small><strong>${months} months</strong></div><div><small>BILLING CYCLE</small><strong>Monthly</strong></div><p>Prices shown cover the full contract term and the listed quantities represent the complete scope.</p></div><div class="vce-longterm-dates"><div><small>START DATE</small><strong>${dateLabel(quote.contract_start)}</strong></div><div><small>END DATE</small><strong>${dateLabel(quote.contract_end)}</strong></div></div>`;
      const contract=paper.querySelector('.vtp-quote-contract');
      if(contract) contract.replaceWith(details); else paper.querySelector('table')?.before(details);
      const note=document.createElement('div'); note.className='vce-longterm-note';
      note.innerHTML=`<strong>Monthly payment:</strong> The total contract fee will be invoiced in ${months} equal monthly installments of ${currency} ${monthly}, payable at the end of each monthly service period. Each invoice is due within 7 days of its issue date. Quantities shown represent total deliverables for the ${months}-month contract.`;
      paper.querySelector('table')?.after(note);
    } catch(e) {}
  }
  function addLongTermPreviewStyles(){
    if(document.getElementById('vce-longterm-preview-style')) return;
    const style=document.createElement('style'); style.id='vce-longterm-preview-style';
    style.textContent=`.vce-longterm-details{margin:18px 0 20px;border:1px solid #d9c9e8;background:#faf7fd}.vce-longterm-summary{display:grid;grid-template-columns:1fr 1fr 2fr;align-items:center;background:#f4eff9}.vce-longterm-summary>div,.vce-longterm-summary>p{padding:13px 16px;margin:0}.vce-longterm-summary>div+div{border-left:1px solid #e3d7ed}.vce-longterm-summary small,.vce-longterm-dates small{display:block;color:#786890;font-size:10px;letter-spacing:.08em;font-weight:800}.vce-longterm-summary strong,.vce-longterm-dates strong{display:block;color:#5d3388;font-size:17px;margin-top:3px}.vce-longterm-summary p{color:#6d6577;font-size:12px;line-height:1.45}.vce-longterm-dates{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #e3d7ed}.vce-longterm-dates>div{padding:12px 16px;background:#fff}.vce-longterm-dates>div+div{border-left:1px solid #e3d7ed}.vce-longterm-note{margin:0 0 18px;padding:13px 16px;border-left:3px solid #7440a1;background:#fbf8fe;color:#695d78;font-size:12px;line-height:1.55}.vce-longterm-note strong{color:#5d3388}@media(max-width:700px){.vce-longterm-summary{grid-template-columns:1fr 1fr}.vce-longterm-summary p{grid-column:1/-1;border-top:1px solid #e3d7ed}.vce-longterm-dates{grid-template-columns:1fr}}
    `; document.head.appendChild(style);
  }
  async function addTemplateSection(){
    if (!window.VTP || !window.VTP.root) return;
    const heading=[...document.querySelectorAll('h1,h2')].find(x=>x.textContent.trim()==='Files & Brand');
    if (!heading) { lastRoot=null; return; }
    const root=heading.closest('.vtp-page')||heading.parentElement;
    if (!root || root===lastRoot || root.querySelector('[data-vce-template-section]')) return;
    lastRoot=root;
    try {
      const response=await fetch(window.VTP.root+'quotation-template-links',{credentials:'same-origin',headers:{'X-WP-Nonce':window.VTP.nonce}});
      const data=await response.json(); if(!response.ok||!data.templates) return;
      const cards=data.templates.map(item=>`<article class="vtp-card vtp-file-card"><div class="vtp-file-icon drive">PDF</div><div class="vtp-file-copy"><div class="vtp-card-head"><div><h3>${esc(item.label)}</h3><small>Google Drive · Replaceable quotation reference</small></div><span class="vtp-chip amber">Admin only</span></div><p>Private reference sample used when preparing this quotation type.</p><div class="vtp-card-actions">${item.url?`<a class="vtp-btn small" href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">Open Drive sample ↗</a>`:''}</div>${data.admin?`<form data-vce-template-form><input type="hidden" name="key" value="${esc(item.key)}"><input name="url" type="url" value="${esc(item.url)}" placeholder="Google Drive PDF link" required><button class="vtp-btn small primary">Save sample link</button></form>`:''}</div></article>`).join('');
      const section=document.createElement('section'); section.className='vtp-files-section'; section.dataset.vceTemplateSection='1';
      section.innerHTML=`<div class="vtp-section-heading"><div><span class="vtp-eyebrow">QUOTATION REFERENCES</span><h2>Drive quotation samples</h2><p>Update these private Google Drive references whenever you replace a quotation design.</p></div></div><div class="vtp-grid two vtp-files-brand-grid">${cards}</div>`;
      root.appendChild(section);
      section.addEventListener('submit',async e=>{e.preventDefault();const form=e.target;const body={key:form.elements.key.value,url:form.elements.url.value};const r=await fetch(window.VTP.root+'quotation-template-links',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':window.VTP.nonce,'Content-Type':'application/json'},body:JSON.stringify(body)});if(r.ok){form.querySelector('button').textContent='Saved';}else{form.querySelector('button').textContent='Try again';}});
    } catch(e) {}
  }
  function patchQuotationReference(){
    const reference=window.VTPQuoteReference;
    if(!reference?.render || reference.render.__vceLongTermPatched) return;
    const original=reference.render;
    const wrapped=(quote,background)=>{
      const months=Number(quote?.contract_months||0);
      if(Number(quote?.period_index||0)===0 && months>1 && Array.isArray(quote.items)){
        const adapted={...quote,items:quote.items.map(item=>{
          const totalQuantity=Math.max(0.01,Number(item.quantity||1));
          return {...item,quantity:totalQuantity,durationMonths:months,term:months===12?'year':`m${months}`,durationDiscount:months===12?10:months===6?5:0,annualDiscount:months===12?10:months===6?5:0,quantityScope:'contract'};
        })};
        return original(adapted,background);
      }
      return original(quote,background);
    };
    wrapped.__vceLongTermPatched=true;
    reference.render=wrapped;
  }
  const observer=()=>{patchQuotationReference();addTemplateSection();addContractFields();organizeQuotationCards();addLongTermPreviewStyles();enhanceLongTermPreview();};
  new MutationObserver(observer).observe(document.body,{childList:true,subtree:true}); observer();
})();
JS
    ;
    echo '</script>';
});
