<?php

if (!defined('ABSPATH')) {
    exit;
}

class VTP_Catalog {
    private const NS = 'vibes/v1';
    private const SAR_PER_USD = 3.75;

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'cors'], 10, 4);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'vtp_';
        dbDelta("CREATE TABLE {$p}catalog_categories (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(80) NOT NULL,
            name varchar(190) NOT NULL,
            code varchar(20) NOT NULL DEFAULT '',
            sort_order int NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY external_id (external_id), KEY active_sort (active,sort_order)
        ) {$charset};");
        dbDelta("CREATE TABLE {$p}catalog_services (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(80) NOT NULL,
            category_id bigint unsigned NOT NULL,
            label varchar(20) NOT NULL DEFAULT '',
            name varchar(190) NOT NULL,
            description longtext,
            unit varchar(80) NOT NULL DEFAULT 'service',
            one_time_price decimal(14,2) NOT NULL DEFAULT 0,
            monthly_price decimal(14,2) NOT NULL DEFAULT 0,
            yearly_price decimal(14,2) NOT NULL DEFAULT 0,
            one_time_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            monthly_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            yearly_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            yearly_discount decimal(5,2) NOT NULL DEFAULT 0,
            custom tinyint(1) NOT NULL DEFAULT 0,
            starting_price tinyint(1) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY external_id (external_id), KEY category_id (category_id), KEY active_sort (active,sort_order)
        ) {$charset};");
        dbDelta("CREATE TABLE {$p}catalog_packages (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(80) NOT NULL,
            name varchar(190) NOT NULL,
            description longtext,
            one_time_price decimal(14,2) NOT NULL DEFAULT 0,
            monthly_price decimal(14,2) NOT NULL DEFAULT 0,
            yearly_price decimal(14,2) NOT NULL DEFAULT 0,
            one_time_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            monthly_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            yearly_price_usd decimal(14,2) NOT NULL DEFAULT 0,
            yearly_discount decimal(5,2) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY external_id (external_id), KEY active_sort (active,sort_order)
        ) {$charset};");
        dbDelta("CREATE TABLE {$p}catalog_package_items (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            package_id bigint unsigned NOT NULL,
            service_id bigint unsigned NOT NULL,
            quantity decimal(10,2) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY package_service (package_id,service_id), KEY package_id (package_id), KEY service_id (service_id)
        ) {$charset};");
        self::seed();
        self::migrate_usd_prices();
    }

    private static function migrate_usd_prices(): void {
        global $wpdb;
        $p = $wpdb->prefix . 'vtp_';
        // Existing catalog values were maintained in SAR. Seed equivalent USD values
        // once for older installations. Current responses derive both currencies
        // from a single base price, and edits synchronize both stored values.
        $wpdb->query("UPDATE {$p}catalog_services SET
            one_time_price_usd = ROUND(one_time_price / 3.75, 2),
            monthly_price_usd = ROUND(monthly_price / 3.75, 2),
            yearly_price_usd = ROUND(yearly_price / 3.75, 2)
            WHERE one_time_price_usd = 0 AND monthly_price_usd = 0 AND yearly_price_usd = 0
              AND (one_time_price > 0 OR monthly_price > 0 OR yearly_price > 0)");
        $wpdb->query("UPDATE {$p}catalog_packages SET
            one_time_price_usd = ROUND(one_time_price / 3.75, 2),
            monthly_price_usd = ROUND(monthly_price / 3.75, 2),
            yearly_price_usd = ROUND(yearly_price / 3.75, 2)
            WHERE one_time_price_usd = 0 AND monthly_price_usd = 0 AND yearly_price_usd = 0
              AND (one_time_price > 0 OR monthly_price > 0 OR yearly_price > 0)");
        update_option('vtp_catalog_usd_prices_migrated', 1, false);
    }

    private static function seed(): void {
        global $wpdb;
        $p = $wpdb->prefix . 'vtp_';
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}catalog_services") > 0) return;
        $file = VTP_DIR . 'data/default-catalog.json';
        if (!is_readable($file)) return;
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) return;
        $now = current_time('mysql');
        $category_ids = [];
        foreach (($data['categories'] ?? []) as $row) {
            $wpdb->insert("{$p}catalog_categories", [
                'external_id' => sanitize_key($row['externalId'] ?? wp_generate_uuid4()),
                'name' => sanitize_text_field($row['name'] ?? ''),
                'code' => strtoupper(sanitize_text_field($row['code'] ?? '')),
                'sort_order' => absint($row['sortOrder'] ?? 0),
                'active' => !empty($row['active']) ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $category_ids[(string) ($row['name'] ?? '')] = (int) $wpdb->insert_id;
        }
        $service_ids = [];
        foreach (($data['services'] ?? []) as $row) {
            $external = sanitize_key($row['externalId'] ?? wp_generate_uuid4());
            $wpdb->insert("{$p}catalog_services", [
                'external_id' => $external,
                'category_id' => (int) ($category_ids[(string) ($row['category'] ?? '')] ?? 0),
                'label' => strtoupper(sanitize_text_field($row['label'] ?? '')),
                'name' => sanitize_text_field($row['name'] ?? ''),
                'description' => sanitize_textarea_field($row['description'] ?? ''),
                'unit' => sanitize_text_field($row['unit'] ?? 'service'),
                'one_time_price' => self::money($row['oneTimePrice'] ?? 0),
                'monthly_price' => self::money($row['monthlyPrice'] ?? 0),
                'yearly_price' => self::money($row['yearlyPrice'] ?? 0),
                'yearly_discount' => self::discount($row['yearlyDiscount'] ?? 0),
                'custom' => !empty($row['custom']) ? 1 : 0,
                'starting_price' => !empty($row['startingPrice']) ? 1 : 0,
                'active' => !empty($row['active']) ? 1 : 0,
                'sort_order' => absint($row['sortOrder'] ?? 0),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $service_ids[$external] = (int) $wpdb->insert_id;
        }
        foreach (($data['packages'] ?? []) as $row) {
            $wpdb->insert("{$p}catalog_packages", [
                'external_id' => sanitize_key($row['externalId'] ?? wp_generate_uuid4()),
                'name' => sanitize_text_field($row['name'] ?? ''),
                'description' => sanitize_textarea_field($row['description'] ?? ''),
                'one_time_price' => self::money($row['oneTimePrice'] ?? 0),
                'monthly_price' => self::money($row['monthlyPrice'] ?? 0),
                'yearly_price' => self::money($row['yearlyPrice'] ?? 0),
                'yearly_discount' => self::discount($row['yearlyDiscount'] ?? 0),
                'active' => !empty($row['active']) ? 1 : 0,
                'sort_order' => absint($row['sortOrder'] ?? 0),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $package_id = (int) $wpdb->insert_id;
            foreach (($row['items'] ?? []) as $index => $item) {
                $service_id = $service_ids[sanitize_key($item['serviceExternalId'] ?? '')] ?? 0;
                if (!$service_id) continue;
                $wpdb->insert("{$p}catalog_package_items", [
                    'package_id' => $package_id, 'service_id' => $service_id,
                    'quantity' => max(0.01, (float) ($item['quantity'] ?? 1)), 'sort_order' => $index + 1,
                ]);
            }
        }
    }

    public static function routes(): void {
        register_rest_route(self::NS, '/public/catalog', [
            'methods' => ['GET', 'OPTIONS'], 'callback' => [self::class, 'public_catalog'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/catalog', [
            'methods' => 'GET', 'callback' => [self::class, 'admin_catalog'], 'permission_callback' => [self::class, 'admin_only'],
        ]);
        foreach (['categories' => 'category', 'services' => 'service', 'packages' => 'package'] as $type => $singular) {
            register_rest_route(self::NS, '/catalog/' . $type, [
                'methods' => 'POST', 'callback' => [self::class, 'create_' . $singular], 'permission_callback' => [self::class, 'admin_only'],
            ]);
            register_rest_route(self::NS, '/catalog/' . $type . '/(?P<id>\d+)', [
                ['methods' => ['PATCH', 'POST'], 'callback' => [self::class, 'update_' . $singular], 'permission_callback' => [self::class, 'admin_only']],
                ['methods' => 'DELETE', 'callback' => [self::class, 'delete_' . $singular], 'permission_callback' => [self::class, 'admin_only']],
            ]);
        }
    }

    public static function cors($served, $result, $request, $server) {
        if (strpos($request->get_route(), '/' . self::NS . '/public/catalog') === 0) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
        }
        return $served;
    }

    public static function admin_only(): bool { return current_user_can('vtp_manage_employees'); }
    public static function public_catalog(WP_REST_Request $request) {
        if ($request->get_method() === 'OPTIONS') return new WP_REST_Response(null, 204);
        return self::catalog(false);
    }
    public static function admin_catalog() { return self::catalog(true); }

    private static function catalog(bool $include_inactive): array {
        global $wpdb;
        $p = $wpdb->prefix . 'vtp_';
        $where = $include_inactive ? '' : ' WHERE active = 1';
        $categories = $wpdb->get_results("SELECT * FROM {$p}catalog_categories{$where} ORDER BY sort_order,id", ARRAY_A);
        $services = $wpdb->get_results("SELECT * FROM {$p}catalog_services{$where} ORDER BY sort_order,id", ARRAY_A);
        $packages = $wpdb->get_results("SELECT * FROM {$p}catalog_packages{$where} ORDER BY sort_order,id", ARRAY_A);
        $category_names = [];
        foreach ($categories as &$row) {
            $category_names[(int) $row['id']] = $row['name'];
            $row = self::category_dto($row);
        }
        unset($row);
        foreach ($services as &$row) $row = self::service_dto($row, $category_names[(int) $row['category_id']] ?? '');
        unset($row);
        $items = $wpdb->get_results("SELECT i.*,s.external_id service_external_id,s.name service_name,s.label service_label FROM {$p}catalog_package_items i JOIN {$p}catalog_services s ON s.id=i.service_id ORDER BY i.sort_order,i.id", ARRAY_A);
        $by_package = [];
        foreach ($items as $item) $by_package[(int) $item['package_id']][] = [
            'serviceId' => (int) $item['service_id'], 'serviceExternalId' => $item['service_external_id'],
            'serviceName' => $item['service_name'], 'serviceLabel' => $item['service_label'], 'quantity' => (float) $item['quantity'],
        ];
        foreach ($packages as &$row) $row = self::package_dto($row, $by_package[(int) $row['id']] ?? []);
        return ['categories' => array_values($categories), 'services' => array_values($services), 'packages' => array_values($packages), 'currency' => 'SAR', 'sarPerUsd' => self::SAR_PER_USD, 'updatedAt' => current_time('c')];
    }

    private static function category_dto(array $r): array { return ['id'=>(int)$r['id'],'externalId'=>$r['external_id'],'name'=>$r['name'],'code'=>$r['code'],'sortOrder'=>(int)$r['sort_order'],'active'=>(bool)$r['active']]; }
    private static function service_dto(array $r, string $category): array {
        return array_merge(self::base_price_dto($r), [
            'id'=>(int)$r['id'],'externalId'=>$r['external_id'],'categoryId'=>(int)$r['category_id'],'category'=>$category,
            'label'=>$r['label'],'name'=>$r['name'],'description'=>$r['description'],'unit'=>$r['unit'],
            'custom'=>(bool)$r['custom'],'startingPrice'=>(bool)$r['starting_price'],'active'=>(bool)$r['active'],'sortOrder'=>(int)$r['sort_order'],
        ]);
    }
    private static function package_dto(array $r, array $items): array {
        return array_merge(self::base_price_dto($r), [
            'id'=>(int)$r['id'],'externalId'=>$r['external_id'],'name'=>$r['name'],'description'=>$r['description'],
            'active'=>(bool)$r['active'],'sortOrder'=>(int)$r['sort_order'],'items'=>$items,
        ]);
    }
    private static function body(WP_REST_Request $r): array { $v=$r->get_json_params(); return is_array($v)?$v:[]; }
    private static function money($v): float { return round(max(0, (float) $v), 2); }
    private static function base_price_dto(array $r): array {
        // Keep the existing SAR base unchanged. The old recurring fields remain
        // derived aliases for calculator embeds that still read those names.
        $sar = self::money($r['one_time_price']);
        $usd = self::money($sar / self::SAR_PER_USD);
        return [
            'oneTimePrice' => $sar, 'oneTimePriceUsd' => $usd,
            'monthlyPrice' => $sar, 'monthlyPriceUsd' => $usd,
            'yearlyPrice' => self::money($sar * 12),
            'yearlyPriceUsd' => self::money($sar * 12 / self::SAR_PER_USD),
            'yearlyDiscount' => 0,
        ];
    }
    private static function base_price_values(array $b, bool $create): array {
        $has_sar = array_key_exists('oneTimePrice', $b);
        $has_usd = array_key_exists('oneTimePriceUsd', $b);
        if (!$create && !$has_sar && !$has_usd) return [];
        // The last currency edited is authoritative. Do not convert a rounded
        // USD display back to SAR when the administrator actually edited SAR.
        $use_usd = $has_usd && (($b['priceCurrency'] ?? '') === 'USD' || !$has_sar);
        $usd = $use_usd ? self::money($b['oneTimePriceUsd']) : 0;
        $sar = $use_usd ? self::money($usd * self::SAR_PER_USD) : self::money($b['oneTimePrice'] ?? 0);
        if (!$use_usd) $usd = self::money($sar / self::SAR_PER_USD);
        return [
            'one_time_price' => $sar, 'one_time_price_usd' => $usd,
            'monthly_price' => $sar, 'monthly_price_usd' => $usd,
            'yearly_price' => self::money($sar * 12),
            'yearly_price_usd' => self::money($sar * 12 / self::SAR_PER_USD),
            'yearly_discount' => 0,
        ];
    }
    private static function discount($v): float { return round(min(100, max(0, (float) $v)), 2); }
    private static function external(string $prefix): string { return $prefix . '-' . strtolower(wp_generate_password(8, false, false)); }

    public static function create_category(WP_REST_Request $r) { global $wpdb; $b=self::body($r); if(empty($b['name']))return new WP_Error('required','Category name is required.',['status'=>400]); $now=current_time('mysql'); $wpdb->insert($wpdb->prefix.'vtp_catalog_categories',['external_id'=>self::external('cat'),'name'=>sanitize_text_field($b['name']),'code'=>strtoupper(sanitize_text_field($b['code']??'')),'sort_order'=>absint($b['sortOrder']??999),'active'=>isset($b['active'])?(int)(bool)$b['active']:1,'created_at'=>$now,'updated_at'=>$now]); return self::catalog(true); }
    public static function update_category(WP_REST_Request $r) { global $wpdb; $b=self::body($r); $data=[]; foreach(['name','code'] as $k)if(array_key_exists($k,$b))$data[$k==='code'?'code':'name']=$k==='code'?strtoupper(sanitize_text_field($b[$k])):sanitize_text_field($b[$k]); if(isset($b['sortOrder']))$data['sort_order']=absint($b['sortOrder']); if(isset($b['active']))$data['active']=(int)(bool)$b['active']; $data['updated_at']=current_time('mysql'); $wpdb->update($wpdb->prefix.'vtp_catalog_categories',$data,['id'=>absint($r['id'])]); return self::catalog(true); }
    public static function delete_category(WP_REST_Request $r) { global $wpdb; $id=absint($r['id']); if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}vtp_catalog_services WHERE category_id=%d",$id)))return new WP_Error('in_use','Move or delete this category’s services first.',['status'=>409]); $wpdb->delete($wpdb->prefix.'vtp_catalog_categories',['id'=>$id]); return self::catalog(true); }

    private static function service_values(array $b, bool $create): array { $d=[]; $map=['categoryId'=>'category_id','name'=>'name','label'=>'label','description'=>'description','unit'=>'unit','sortOrder'=>'sort_order']; foreach($map as $in=>$out)if($create||array_key_exists($in,$b))$d[$out]=in_array($in,['categoryId','sortOrder'],true)?absint($b[$in]??0):($in==='description'?sanitize_textarea_field($b[$in]??''):sanitize_text_field($b[$in]??'')); if(isset($d['label']))$d['label']=strtoupper($d['label']); $d+=self::base_price_values($b,$create); foreach(['custom','startingPrice','active'] as $in)if($create||array_key_exists($in,$b))$d[$in==='startingPrice'?'starting_price':$in]=(int)(bool)($b[$in]??($in==='active')); return $d; }
    public static function create_service(WP_REST_Request $r) { global $wpdb; $b=self::body($r); if(empty($b['name'])||empty($b['categoryId']))return new WP_Error('required','Service name and category are required.',['status'=>400]); $d=self::service_values($b,true); $d+=['external_id'=>self::external('svc'),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]; $wpdb->insert($wpdb->prefix.'vtp_catalog_services',$d); return self::catalog(true); }
    public static function update_service(WP_REST_Request $r) { global $wpdb; $d=self::service_values(self::body($r),false); $d['updated_at']=current_time('mysql'); $wpdb->update($wpdb->prefix.'vtp_catalog_services',$d,['id'=>absint($r['id'])]); return self::catalog(true); }
    public static function delete_service(WP_REST_Request $r) { global $wpdb; $id=absint($r['id']); $wpdb->delete($wpdb->prefix.'vtp_catalog_package_items',['service_id'=>$id]); $wpdb->delete($wpdb->prefix.'vtp_catalog_services',['id'=>$id]); return self::catalog(true); }

    private static function package_values(array $b, bool $create): array { $d=[]; foreach(['name','description'] as $k)if($create||array_key_exists($k,$b))$d[$k]=$k==='description'?sanitize_textarea_field($b[$k]??''):sanitize_text_field($b[$k]??''); $d+=self::base_price_values($b,$create); if($create||array_key_exists('sortOrder',$b))$d['sort_order']=absint($b['sortOrder']??999); if($create||array_key_exists('active',$b))$d['active']=(int)(bool)($b['active']??true); return $d; }
    private static function save_package_items(int $package_id, array $items): void { global $wpdb; $table=$wpdb->prefix.'vtp_catalog_package_items'; $wpdb->delete($table,['package_id'=>$package_id]); foreach($items as $i=>$item){$sid=absint($item['serviceId']??0); if(!$sid)continue; $wpdb->insert($table,['package_id'=>$package_id,'service_id'=>$sid,'quantity'=>max(.01,(float)($item['quantity']??1)),'sort_order'=>$i+1]);} }
    public static function create_package(WP_REST_Request $r) { global $wpdb; $b=self::body($r); if(empty($b['name']))return new WP_Error('required','Package name is required.',['status'=>400]); $d=self::package_values($b,true); $d+=['external_id'=>self::external('pkg'),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]; $wpdb->insert($wpdb->prefix.'vtp_catalog_packages',$d); self::save_package_items((int)$wpdb->insert_id,is_array($b['items']??null)?$b['items']:[]); return self::catalog(true); }
    public static function update_package(WP_REST_Request $r) { global $wpdb; $b=self::body($r); $id=absint($r['id']); $d=self::package_values($b,false); $d['updated_at']=current_time('mysql'); $wpdb->update($wpdb->prefix.'vtp_catalog_packages',$d,['id'=>$id]); if(array_key_exists('items',$b))self::save_package_items($id,is_array($b['items'])?$b['items']:[]); return self::catalog(true); }
    public static function delete_package(WP_REST_Request $r) { global $wpdb; $id=absint($r['id']); $wpdb->delete($wpdb->prefix.'vtp_catalog_package_items',['package_id'=>$id]); $wpdb->delete($wpdb->prefix.'vtp_catalog_packages',['id'=>$id]); return self::catalog(true); }
}
