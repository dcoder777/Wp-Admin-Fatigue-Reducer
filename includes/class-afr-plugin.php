<?php

if (! defined('ABSPATH')) {
    exit;
}

class AFR_Plugin
{
    private const META_NOTICE_DATA = 'afr_notice_learning';
    private const META_PREFS = 'afr_preferences';

    private static $instance = null;
    private $buffering = false;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Capture rendered notices after they are emitted.
        add_action('admin_notices', [$this, 'start_notice_capture'], 0);
        add_action('admin_notices', [$this, 'process_notice_capture'], PHP_INT_MAX);

        add_action('network_admin_notices', [$this, 'start_notice_capture'], 0);
        add_action('network_admin_notices', [$this, 'process_notice_capture'], PHP_INT_MAX);

        add_action('user_admin_notices', [$this, 'start_notice_capture'], 0);
        add_action('user_admin_notices', [$this, 'process_notice_capture'], PHP_INT_MAX);

        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'handle_post_actions']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('admin-fatigue-reducer', false, dirname(plugin_basename(AFR_PLUGIN_FILE)) . '/languages');
    }

    public function enqueue_admin_assets(): void
    {
        wp_enqueue_script(
            'afr-admin',
            AFR_PLUGIN_URL . 'assets/admin.js',
            ['wp-api-fetch'],
            AFR_PLUGIN_VERSION,
            true
        );

        wp_localize_script('afr-admin', 'AFRData', [
            'root' => esc_url_raw(rest_url('afr/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function start_notice_capture(): void
    {
        if ($this->buffering || ! is_user_logged_in()) {
            return;
        }

        $this->buffering = true;
        ob_start();
    }

    public function process_notice_capture(): void
    {
        if (! $this->buffering) {
            return;
        }

        $this->buffering = false;
        $captured = ob_get_clean();

        if (! is_string($captured) || '' === trim($captured)) {
            return;
        }

        try {
            $processed = $this->render_processed_notices($captured, get_current_user_id());
            echo $processed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } catch (Throwable $e) {
            // Safe fallback: output notices exactly as originally rendered.
            echo $captured; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    private function render_processed_notices(string $html, int $user_id): string
    {
        $chunks = preg_split('/(?=<div[^>]+class="[^"]*notice)/i', $html);
        if (! is_array($chunks) || empty($chunks)) {
            return $html;
        }

        $prefs = $this->get_user_preferences($user_id);
        $data = $this->get_user_notice_data($user_id);
        $output = '';
        $digest = [];

        foreach ($chunks as $chunk) {
            if ('' === trim($chunk)) {
                continue;
            }

            if (false === stripos($chunk, 'notice')) {
                $output .= $chunk;
                continue;
            }

            $notice = $this->extract_notice_metadata($chunk);
            if (! $notice['id']) {
                $output .= $chunk;
                continue;
            }

            $id = $notice['id'];
            if (! isset($data[$id])) {
                $data[$id] = [
                    'id' => $id,
                    'source' => $notice['source'],
                    'first_seen' => current_time('mysql'),
                    'last_seen' => current_time('mysql'),
                    'display_count' => 0,
                    'dismissed_count' => 0,
                    'interaction_count' => 0,
                    'last_score' => 0,
                    'last_hidden' => '',
                    'critical' => $notice['critical'] ? 1 : 0,
                    'sample' => wp_strip_all_tags($notice['text']),
                ];
            }

            $data[$id]['display_count']++;
            $data[$id]['last_seen'] = current_time('mysql');
            $data[$id]['source'] = $notice['source'];
            $data[$id]['critical'] = $notice['critical'] ? 1 : 0;
            $score = $this->score_notice($notice, $data[$id]);
            $data[$id]['last_score'] = $score;

            $source_allowed = ! isset($prefs['plugin_visibility'][$notice['source']]) || (bool) $prefs['plugin_visibility'][$notice['source']];

            $should_hide = ! $notice['critical'] && $source_allowed && $this->should_auto_hide($score, $data[$id], $prefs);

            if ($should_hide) {
                $data[$id]['last_hidden'] = current_time('mysql');
                $digest_key = $notice['source'] . '|' . ($notice['type'] ?: 'general');
                if (! isset($digest[$digest_key])) {
                    $digest[$digest_key] = [
                        'source' => $notice['source'],
                        'type' => $notice['type'] ?: 'general',
                        'count' => 0,
                        'items' => [],
                    ];
                }
                $digest[$digest_key]['count']++;
                $digest[$digest_key]['items'][] = [
                    'id' => $id,
                    'preview' => mb_substr(wp_strip_all_tags($notice['text']), 0, 220),
                ];
                continue;
            }

            $output .= $this->annotate_notice_html($chunk, $id);
        }

        $this->save_user_notice_data($user_id, $data);

        if (! empty($digest)) {
            $output .= $this->render_digest_notice($digest);
        }

        return $output;
    }


    private function annotate_notice_html(string $notice_html, string $id): string
    {
        if ('' === $id) {
            return $notice_html;
        }

        return (string) preg_replace('/<div\b/i', '<div data-afr-notice-id="' . esc_attr($id) . '" ', $notice_html, 1);
    }

    private function extract_notice_metadata(string $notice_html): array
    {
        $classes = '';
        if (preg_match('/class="([^"]+)"/i', $notice_html, $matches)) {
            $classes = $matches[1];
        }

        $text = trim(wp_strip_all_tags($notice_html));
        $source = $this->guess_notice_source($classes, $notice_html);
        $type = $this->guess_notice_type($classes);
        $critical = $this->is_critical_notice($classes, $text);

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $text)));
        $id = md5($source . '|' . $type . '|' . $normalized);

        return [
            'id' => $id,
            'source' => $source,
            'type' => $type,
            'critical' => $critical,
            'text' => $text,
            'classes' => $classes,
            'raw' => $notice_html,
        ];
    }

    private function guess_notice_source(string $classes, string $notice_html): string
    {
        $class_list = preg_split('/\s+/', strtolower(trim($classes)));
        if (! is_array($class_list)) {
            return 'unknown';
        }

        foreach ($class_list as $class) {
            if (in_array($class, ['notice', 'is-dismissible', 'notice-error', 'notice-warning', 'notice-success', 'notice-info', 'updated', 'error', 'update-nag'], true)) {
                continue;
            }

            if (str_contains($class, 'plugin') || str_contains($class, 'woocommerce') || str_contains($class, 'yoast') || str_contains($class, 'elementor')) {
                return sanitize_key($class);
            }
        }

        if (false !== stripos($notice_html, 'wordpress.org')) {
            return 'wordpress-core';
        }

        return 'unknown';
    }

    private function guess_notice_type(string $classes): string
    {
        $classes = strtolower($classes);
        if (str_contains($classes, 'notice-error') || str_contains($classes, 'error')) {
            return 'error';
        }
        if (str_contains($classes, 'notice-warning')) {
            return 'warning';
        }
        if (str_contains($classes, 'notice-success') || str_contains($classes, 'updated')) {
            return 'success';
        }

        return 'info';
    }

    private function is_critical_notice(string $classes, string $text): bool
    {
        $critical_patterns = [
            'security',
            'fatal error',
            'critical error',
            'database upgrade required',
            'update wordpress',
            'php update required',
            'site health critical',
        ];

        $classes = strtolower($classes);
        $text = strtolower($text);

        if (str_contains($classes, 'update-nag') || str_contains($classes, 'notice-error')) {
            return true;
        }

        foreach ($critical_patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Example lightweight scoring algorithm (no ML):
     * score = (display_count * 2) + dismissed_count - (interaction_count * 3) + source_penalty + type_penalty.
     */
    private function score_notice(array $notice, array $history): int
    {
        $display_count = (int) ($history['display_count'] ?? 0);
        $dismissed_count = (int) ($history['dismissed_count'] ?? 0);
        $interaction_count = (int) ($history['interaction_count'] ?? 0);

        $source_penalty = ('unknown' === $notice['source']) ? 1 : 0;
        $type_penalty = ('info' === $notice['type']) ? 1 : 0;

        $score = ($display_count * 2) + $dismissed_count - ($interaction_count * 3) + $source_penalty + $type_penalty;

        if ($notice['critical']) {
            $score -= 100;
        }

        return $score;
    }

    private function should_auto_hide(int $score, array $history, array $prefs): bool
    {
        $threshold = (int) ($prefs['auto_hide_threshold'] ?? 7);
        $display_count = (int) ($history['display_count'] ?? 0);

        return $display_count >= 3 && $score >= $threshold;
    }

    private function render_digest_notice(array $digest): string
    {
        $title = esc_html__('Admin Fatigue Reducer grouped low-value notices', 'admin-fatigue-reducer');
        $html = '<div class="notice notice-info"><p><strong>' . $title . '</strong></p><details>';

        foreach ($digest as $group) {
            $summary = sprintf(
                /* translators: 1: source plugin 2: notice type 3: count */
                esc_html__('%1$s (%2$s): %3$d hidden notices', 'admin-fatigue-reducer'),
                esc_html($group['source']),
                esc_html($group['type']),
                (int) $group['count']
            );
            $html .= '<details style="margin-bottom:8px"><summary>' . $summary . '</summary><ul>';
            foreach ($group['items'] as $item) {
                $html .= '<li data-afr-notice-id="' . esc_attr($item['id']) . '">' . esc_html($item['preview']) . '</li>';
            }
            $html .= '</ul></details>';
        }

        $html .= '</details></div>';

        return $html;
    }

    public function register_admin_pages(): void
    {
        add_options_page(
            __('Admin Fatigue Reducer', 'admin-fatigue-reducer'),
            __('Admin Fatigue Reducer', 'admin-fatigue-reducer'),
            'manage_options',
            'admin-fatigue-reducer',
            [$this, 'render_settings_page']
        );

        add_management_page(
            __('Notice Summary', 'admin-fatigue-reducer'),
            __('Notice Summary', 'admin-fatigue-reducer'),
            'read',
            'afr-notice-summary',
            [$this, 'render_summary_page']
        );
    }

    public function handle_post_actions(): void
    {
        if (! is_admin() || empty($_POST['afr_action'])) {
            return;
        }

        if (! check_admin_referer('afr_settings_action', 'afr_nonce')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        $user_id = get_current_user_id();
        if ('reset_learning' === $_POST['afr_action']) {
            delete_user_meta($user_id, self::META_NOTICE_DATA);
        }

        if ('save_preferences' === $_POST['afr_action']) {
            $prefs = $this->get_user_preferences($user_id);
            $prefs['auto_hide_threshold'] = max(3, (int) ($_POST['auto_hide_threshold'] ?? 7));
            $prefs['summary_period'] = in_array($_POST['summary_period'] ?? 'daily', ['daily', 'weekly'], true) ? $_POST['summary_period'] : 'daily';

            $saved_visibility = [];
            $raw_visibility = isset($_POST['afr_plugin_visibility']) && is_array($_POST['afr_plugin_visibility'])
                ? $_POST['afr_plugin_visibility']
                : [];

            $known_sources = [];
            foreach ($this->get_user_notice_data($user_id) as $item) {
                $known_sources[] = sanitize_key((string) ($item['source'] ?? 'unknown'));
            }

            foreach (array_unique($known_sources) as $source) {
                $saved_visibility[$source] = isset($raw_visibility[$source]);
            }

            $prefs['plugin_visibility'] = $saved_visibility;
            update_user_meta($user_id, self::META_PREFS, $prefs);
        }

        wp_safe_redirect(add_query_arg(['page' => 'admin-fatigue-reducer', 'afr_saved' => 1], admin_url('options-general.php')));
        exit;
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'admin-fatigue-reducer'));
        }

        $user_id = get_current_user_id();
        $prefs = $this->get_user_preferences($user_id);
        $data = $this->get_user_notice_data($user_id);

        $sources = [];
        foreach ($data as $item) {
            $source = $item['source'] ?? 'unknown';
            $sources[$source] = true;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Admin Fatigue Reducer Settings', 'admin-fatigue-reducer') . '</h1>';

        if (! empty($_GET['afr_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'admin-fatigue-reducer') . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('afr_settings_action', 'afr_nonce');
        echo '<input type="hidden" name="afr_action" value="save_preferences" />';

        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="auto_hide_threshold">' . esc_html__('Auto-hide threshold', 'admin-fatigue-reducer') . '</label></th>';
        echo '<td><input name="auto_hide_threshold" id="auto_hide_threshold" type="number" min="3" value="' . esc_attr((string) ($prefs['auto_hide_threshold'] ?? 7)) . '" />';
        echo '<p class="description">' . esc_html__('Higher values hide fewer notices.', 'admin-fatigue-reducer') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="summary_period">' . esc_html__('Summary cadence', 'admin-fatigue-reducer') . '</label></th>';
        echo '<td><select name="summary_period" id="summary_period">';
        $period = $prefs['summary_period'] ?? 'daily';
        echo '<option value="daily" ' . selected($period, 'daily', false) . '>' . esc_html__('Daily', 'admin-fatigue-reducer') . '</option>';
        echo '<option value="weekly" ' . selected($period, 'weekly', false) . '>' . esc_html__('Weekly', 'admin-fatigue-reducer') . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Per-plugin visibility', 'admin-fatigue-reducer') . '</th><td>';
        if (empty($sources)) {
            echo '<em>' . esc_html__('No tracked notice sources yet.', 'admin-fatigue-reducer') . '</em>';
        } else {
            foreach (array_keys($sources) as $source) {
                $checked = ! isset($prefs['plugin_visibility'][$source]) || $prefs['plugin_visibility'][$source];
                echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="afr_plugin_visibility[' . esc_attr($source) . ']" value="1" ' . checked($checked, true, false) . ' /> ' . esc_html($source) . '</label>';
            }
        }
        echo '<p class="description">' . esc_html__('Unchecked sources are always shown (never auto-hidden).', 'admin-fatigue-reducer') . '</p>';
        echo '</td></tr>';
        echo '</table>';

        submit_button(__('Save preferences', 'admin-fatigue-reducer'));
        echo '</form>';

        echo '<form method="post" style="margin-top:16px">';
        wp_nonce_field('afr_settings_action', 'afr_nonce');
        echo '<input type="hidden" name="afr_action" value="reset_learning" />';
        submit_button(__('Reset learning data', 'admin-fatigue-reducer'), 'delete');
        echo '</form>';

        echo '<h2>' . esc_html__('Recently hidden notices', 'admin-fatigue-reducer') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Source', 'admin-fatigue-reducer') . '</th><th>' . esc_html__('Preview', 'admin-fatigue-reducer') . '</th><th>' . esc_html__('Score', 'admin-fatigue-reducer') . '</th><th>' . esc_html__('Last hidden', 'admin-fatigue-reducer') . '</th></tr></thead><tbody>';

        $rows_printed = 0;
        foreach ($data as $item) {
            if (empty($item['last_hidden'])) {
                continue;
            }
            $rows_printed++;
            echo '<tr><td>' . esc_html($item['source'] ?? 'unknown') . '</td><td>' . esc_html(mb_substr($item['sample'] ?? '', 0, 120)) . '</td><td>' . esc_html((string) ($item['last_score'] ?? 0)) . '</td><td>' . esc_html($item['last_hidden']) . '</td></tr>';
        }

        if (0 === $rows_printed) {
            echo '<tr><td colspan="4"><em>' . esc_html__('No hidden notices yet.', 'admin-fatigue-reducer') . '</em></td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_summary_page(): void
    {
        if (! current_user_can('read')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'admin-fatigue-reducer'));
        }

        $user_id = get_current_user_id();
        $prefs = $this->get_user_preferences($user_id);
        $data = $this->get_user_notice_data($user_id);

        $total_notices = count($data);
        $hidden = 0;
        $dismissed = 0;
        $interactions = 0;

        foreach ($data as $row) {
            if (! empty($row['last_hidden'])) {
                $hidden++;
            }
            $dismissed += (int) ($row['dismissed_count'] ?? 0);
            $interactions += (int) ($row['interaction_count'] ?? 0);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Admin Notice Summary', 'admin-fatigue-reducer') . '</h1>';
        echo '<p>' . esc_html(sprintf(__('Summary cadence: %s', 'admin-fatigue-reducer'), $prefs['summary_period'] ?? 'daily')) . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html(sprintf(__('Tracked notices: %d', 'admin-fatigue-reducer'), $total_notices)) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Auto-hidden notices: %d', 'admin-fatigue-reducer'), $hidden)) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Dismiss actions: %d', 'admin-fatigue-reducer'), $dismissed)) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Interactions (clicks): %d', 'admin-fatigue-reducer'), $interactions)) . '</li>';
        echo '</ul>';
        echo '<p>' . esc_html__('Open Settings → Admin Fatigue Reducer to tune thresholds and plugin visibility.', 'admin-fatigue-reducer') . '</p>';
        echo '</div>';
    }

    public function register_rest_routes(): void
    {
        register_rest_route('afr/v1', '/preferences', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'rest_preferences'],
            'permission_callback' => static function () {
                return current_user_can('read');
            },
        ]);

        register_rest_route('afr/v1', '/analytics', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_analytics'],
            'permission_callback' => static function () {
                return current_user_can('read');
            },
        ]);
    }

    public function rest_preferences(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        if ('GET' === $request->get_method()) {
            return new WP_REST_Response($this->get_user_preferences($user_id));
        }

        $prefs = $this->get_user_preferences($user_id);
        $incoming = $request->get_json_params();

        if (isset($incoming['auto_hide_threshold'])) {
            $prefs['auto_hide_threshold'] = max(3, (int) $incoming['auto_hide_threshold']);
        }

        if (isset($incoming['summary_period']) && in_array($incoming['summary_period'], ['daily', 'weekly'], true)) {
            $prefs['summary_period'] = $incoming['summary_period'];
        }

        if (isset($incoming['plugin_visibility']) && is_array($incoming['plugin_visibility'])) {
            foreach ($incoming['plugin_visibility'] as $source => $allowed) {
                $prefs['plugin_visibility'][sanitize_key((string) $source)] = (bool) $allowed;
            }
        }

        update_user_meta($user_id, self::META_PREFS, $prefs);

        return new WP_REST_Response($prefs);
    }

    public function rest_analytics(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $notice_id = sanitize_text_field((string) ($payload['notice_id'] ?? ''));
        $action = sanitize_key((string) ($payload['action'] ?? ''));

        if ('' === $notice_id || ! in_array($action, ['dismiss', 'interact', 'ignore'], true)) {
            return new WP_REST_Response(['message' => 'Invalid payload'], 400);
        }

        $user_id = get_current_user_id();
        $data = $this->get_user_notice_data($user_id);

        if (! isset($data[$notice_id])) {
            $data[$notice_id] = [
                'id' => $notice_id,
                'source' => 'unknown',
                'first_seen' => current_time('mysql'),
                'last_seen' => current_time('mysql'),
                'display_count' => 0,
                'dismissed_count' => 0,
                'interaction_count' => 0,
                'last_score' => 0,
                'last_hidden' => '',
                'critical' => 0,
                'sample' => '',
            ];
        }

        if ('dismiss' === $action) {
            $data[$notice_id]['dismissed_count']++;
        }

        if ('interact' === $action) {
            $data[$notice_id]['interaction_count']++;
        }

        // `ignore` is intentionally represented by display without interaction;
        // receiving this event updates last seen to support external integrations.
        $data[$notice_id]['last_seen'] = current_time('mysql');

        $this->save_user_notice_data($user_id, $data);

        return new WP_REST_Response(['ok' => true]);
    }

    private function get_user_notice_data(int $user_id): array
    {
        $value = get_user_meta($user_id, self::META_NOTICE_DATA, true);
        return is_array($value) ? $value : [];
    }

    private function save_user_notice_data(int $user_id, array $data): void
    {
        update_user_meta($user_id, self::META_NOTICE_DATA, $data);
    }

    private function get_user_preferences(int $user_id): array
    {
        $defaults = [
            'auto_hide_threshold' => 7,
            'summary_period' => 'daily',
            'plugin_visibility' => [],
        ];

        $prefs = get_user_meta($user_id, self::META_PREFS, true);
        if (! is_array($prefs)) {
            return $defaults;
        }

        return wp_parse_args($prefs, $defaults);
    }
}
