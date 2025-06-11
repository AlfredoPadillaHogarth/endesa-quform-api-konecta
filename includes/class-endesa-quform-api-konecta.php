<?php
// TODO: Cambiar la conexiona Salesforce por la de Konecta
// TODO: Cambiar la estructura de datos de la API de Salesforce por la de Konecta
// Funciones que hay que hacer:
// - get_auth: Obtener el token de autenticacion de la API
// - get_base_form_data: Obtener los datos base del formulario para las solicitudes
// - send_post_request: Enviar una solicitud POST a la API
// - quform_hook_handler: Manejar el envio del formulario

if (!defined('WPINC')) {
    die;
}

class Endesa_Quform_API_Konecta
{
    private $table_name;
    private $table_rows;
    private $api_url;
    private $auth_token;
    private $token_expiry;
    private $forms_id_field_map = array();
    private $max_retries = 4;

    public function __construct() {
        // Initialize variables
        global $wpdb;
        $this->table_name = $wpdb->prefix . ENDESA_API_TABLE_NAME;
        $this->table_rows = array();
        $this->api_url = get_option('endesa_api_konecta_url', 'https://gdidmz-qa.endesa.es/PRE');
        $this->auth_token = '';
        $this->token_expiry = '';
        $this->forms_id_field_map = $this->load_id_field_map_json();
        
        // Add the options page to the admin menu
        add_action('admin_menu', array($this, 'add_options_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add form hook handler to intercept all form submissions
        add_filter('quform_post_process', [$this, 'quform_hook_handler'], 10, 2);
        
        // Setup cron job
        $this->setup_cron();
    }

    //
    // DATABASE MANAGEMENT FUNCTIONS
    //
    /**
     * Insert a new row into the submissions table.
     * @param string $lead_id
     * @param array $form_data
     * @param string $response
     * @param string $response_code
     * @param bool $successfull_sent
     */
    public function insert_submission($lead_id, $form_data, $response, $response_code, $successfull_sent) {
        global $wpdb;
        
        // Convert form data array to JSON string
        $form_data_json = json_encode($form_data);
        
        $wpdb->insert(
            $this->table_name,
            array(
                'lead_id' => $lead_id,
                'form_data' => $form_data_json,
                'response' => $response,
                'response_code' => $response_code,
                'successfull_sent' => $successfull_sent,
                'created_at' => current_time('mysql'),
            )
        );
    }

    /**
     * Loads the JSON from file that contains the form IDs and field mappings.
     * @return array
     */
    private function load_id_field_map_json() {
        $json = file_get_contents(ENDESA_API_KONECTA_PLUGIN_DIR . '/includes/form_fields_ids.json');
        $data = json_decode($json, true);
        return $data;
    }

    //
    // API FUNCTIONS
    //
    /**
     * Get the auth token from the API.
     * @return string|bool
     */
    private function get_auth() {
    }

    /**
     * Get almost empty form data for submissions.
     * @return array
     */
    public function get_base_form_data() {
    }

    /**
     * Get the HTTP code description.
     * @param int $codigo
     * @return string
     */
    private function get_http_code_description($codigo) { 
        $status_textos = array( 
            100 => 'Continue', 
            101 => 'Switching Protocols', 
            102 => 'Processing',            // RFC2518 
            103 => 'Early Hints', 
            200 => 'OK', 
            201 => 'Created', 
            202 => 'Accepted', 
            203 => 'Non-Authoritative Information', 
            204 => 'No Content', 
            205 => 'Reset Content', 
            206 => 'Partial Content', 
            207 => 'Multi-Status',          // RFC4918 
            208 => 'Already Reported',      // RFC5842 
            226 => 'IM Used',               // RFC3229 
            300 => 'Multiple Choices', 
            301 => 'Moved Permanently', 
            302 => 'Found', 
            303 => 'See Other', 
            304 => 'Not Modified', 
            305 => 'Use Proxy', 
            307 => 'Temporary Redirect', 
            308 => 'Permanent Redirect',    // RFC7238 
            400 => 'Bad Request', 
            401 => 'Unauthorized', 
            402 => 'Payment Required', 
            403 => 'Forbidden', 
            404 => 'Not Found', 
            405 => 'Method Not Allowed', 
            406 => 'Not Acceptable', 
            407 => 'Proxy Authentication Required', 
            408 => 'Request Timeout', 
            409 => 'Conflict', 
            410 => 'Gone', 
            411 => 'Length Required', 
            412 => 'Precondition Failed', 
            413 => 'Payload Too Large', 
            414 => 'URI Too Long', 
            415 => 'Unsupported Media Type', 
            416 => 'Range Not Satisfiable', 
            417 => 'Expectation Failed', 
            418 => 'I\'m a teapot',            // RFC2324 
            421 => 'Misdirected Request',      // RFC7540 
            422 => 'Unprocessable Entity',     // RFC4918 
            423 => 'Locked',                   // RFC4918 
            424 => 'Failed Dependency',        // RFC4918 
            425 => 'Too Early',               // RFC8470 
            426 => 'Upgrade Required',         // RFC2817 
            428 => 'Precondition Required',    // RFC6585 
            429 => 'Too Many Requests',        // RFC6585 
            431 => 'Request Header Fields Too Large', // RFC6585 
            451 => 'Unavailable For Legal Reasons', // RFC7725 
            500 => 'Internal Server Error', 
            501 => 'Not Implemented', 
            502 => 'Bad Gateway', 
            503 => 'Service Unavailable', 
            504 => 'Gateway Timeout', 
            505 => 'HTTP Version Not Supported', 
            506 => 'Variant Also Negotiates',  // RFC2295 
            507 => 'Insufficient Storage',     // RFC4918 
            508 => 'Loop Detected',            // RFC5842 
            510 => 'Not Extended',            // RFC2774 
            511 => 'Network Authentication Required', // RFC6585 
        ); 

        return isset($status_textos[$codigo]) ? $status_textos[$codigo] : 'Unknown HTTP Status Code';
    }

    /**
     * Send a POST request to the API.
     * @param string $url
     * @param array $data
     */
    public function send_post_request($url, $data) {
        try {
            $this->get_auth();

            $this->insert_submission(
                $data['lead_id'],
                $data,
                '',
                '',
                false
            );
        } catch (Exception $e) {
            error_log('Error sending POST request: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle the form submission. This function will be called through a hook.
     * @param array $result
     * @param object $form
     * @return object
     */
    public function quform_hook_handler($result, $form) {
        $this->send_post_request($this->api_url . '', $form->getValues());
    }

    //
    // CRON JOB FUNCTIONS
    //
    /**
     * Setup the cron job.
     */
    private function setup_cron() {
        // Schedule the event if it's not already scheduled
        if (!wp_next_scheduled('endesa_api_konecta_cron_hook')) {
            wp_schedule_event(time(), 'twicedaily', 'endesa_api_konecta_cron_hook');
        }

        // Add the action hook for the cron job
        add_action('endesa_api_konecta_cron_hook', array($this, 'do_cron_post'));
    }

    /**
     * The function that runs when the cron job is triggered.
     */
    public function do_cron_post() {
        global $wpdb;
        $query = "SELECT t1.*
        FROM $this->table_name AS t1
        WHERE t1.successfull_sent = false
        AND NOT EXISTS (
            -- Check if there are any successful submissions for this lead_id
            SELECT 1 
            FROM $this->table_name AS t2
            WHERE t2.lead_id = t1.lead_id
            AND t2.successfull_sent = true
        )
        AND (
            -- Only leads with 3 or fewer total submissions
            SELECT COUNT(*)
            FROM $this->table_name AS t3
            WHERE t3.lead_id = t1.lead_id
        ) < $this->max_retries
        AND t1.created_at = (
            -- Only the most recent submission for each lead
            SELECT MAX(t4.created_at)
            FROM $this->table_name AS t4
            WHERE t4.lead_id = t1.lead_id
        );";

        $results = $wpdb->get_results($query, ARRAY_A);
        foreach ($results as $row) {
            $lead_id = $row['lead_id'];
            $data = json_decode($row['form_data'], true);

            // Call the API with the lead ID
            $this->send_post_request($this->api_url . '/BusServ/bxmfexternalleadsbb-api/1.0.0/sendrosetta', $data);
        }
    }

    //
    // ADMIN OPTIONS FUNCTIONS
    //
    /**
     * Add the options page to the admin menu.
     */
    public function add_options_page() {
        add_options_page(
            'Endesa API Konecta Settings',
            'Endesa API Konecta',
            'manage_options',
            'endesa-api-konecta-settings',
            array($this, 'render_options_page')
        );
    }

    /**
     * Add the settings link to the plugin page.
     * @param array $links
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=endesa-api-konecta-settings">Ajustes</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Register the settings for the options page.
     */
    public function register_settings() {
        register_setting('endesa_api_konecta_options', 'endesa_api_konecta_form_ids');
        register_setting('endesa_api_konecta_options', 'endesa_api_konecta_url');
        register_setting('endesa_api_konecta_options', 'endesa_api_konecta_username');
        register_setting('endesa_api_konecta_options', 'endesa_api_konecta_password');

        add_settings_section(
            'endesa_api_konecta_main_section',
            'Form Settings',
            null,
            'endesa-api-konecta-settings'
        );

        add_settings_field(
            'endesa_api_konecta_form_ids',
            'Selected Form IDs',
            array($this, 'render_form_ids_field'),
            'endesa-api-konecta-settings',
            'endesa_api_konecta_main_section'
        );

        add_settings_field(
            'endesa_api_konecta_url',
            'API URL',
            array($this, 'render_api_url_field'),
            'endesa-api-konecta-settings',
            'endesa_api_konecta_main_section'
        );

        add_settings_field(
            'endesa_api_konecta_username',
            'API Username',
            array($this, 'render_username_field'),
            'endesa-api-konecta-settings',
            'endesa_api_konecta_main_section'
        );

        add_settings_field(
            'endesa_api_konecta_password',
            'API Password',
            array($this, 'render_password_field'),
            'endesa-api-konecta-settings',
            'endesa_api_konecta_main_section'
        );
    }
    
    /**
     * Render the options page.
     */
    public function render_options_page() {
        if (!current_user_can('manage_options')) {
            return;
        } ?>

        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('endesa_api_konecta_options');
                do_settings_sections('endesa-api-konecta-settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the API url selector field. Testing or production.
     */
    public function render_api_url_field() {
        $api_url = get_option('endesa_api_konecta_url', 'https://gdidmz-qa.endesa.es/PRE');
        ?>
        <select name="endesa_api_konecta_url">
            <option value="https://gdidmz-qa.endesa.es/PRE" <?php selected($api_url, 'https://gdidmz-qa.endesa.es/PRE'); ?>>Testing</option>
            <option value="https://gdidmz.endesa.es" <?php selected($api_url, 'https://gdidmz.endesa.es'); ?>>Production</option>
        </select>
        <p class="description">Select the API URL to use for submissions.</p>
        <?php
    }

    /**
     * Render the form IDs field.
     */
    public function render_form_ids_field() {
        global $wpdb;
        $selected_forms = get_option('endesa_api_konecta_form_ids', array());
        
        // Get all Quform forms
        $forms = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}quform_forms", ARRAY_A);
        
        if (empty($forms)) {
            echo '<p>No Quform forms found.</p>';
            return;
        } ?>

        <select name="endesa_api_konecta_form_ids[]" multiple="multiple" style="min-width: 300px; min-height: 150px;">
            <?php foreach ($forms as $form): ?>
                <option value="<?php echo esc_attr($form['id']); ?>" 
                    <?php echo in_array($form['id'], $selected_forms) ? 'selected' : ''; ?>>
                    <?php echo esc_html($form['name'] . ' (ID: ' . $form['id'] . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Hold Ctrl/Cmd to select multiple forms</p>
        <?php
    }

    /**
     * Render the username field.
     */
    public function render_username_field() {
        $username = get_option('endesa_api_konecta_username', 'rextzapie001');
        ?>
        <input type="text" name="endesa_api_konecta_username" value="<?php echo esc_attr($username); ?>" style="min-width: 300px;">
        <p class="description">Enter your API username</p>
        <?php
    }

    /**
     * Render the password field.
     */
    public function render_password_field() {
        $password = get_option('endesa_api_konecta_password', '@tEMPORAL$2022');
        ?>
        <input type="password" name="endesa_api_konecta_password" value="<?php echo esc_attr($password); ?>" style="min-width: 300px;">
        <p class="description">Enter your API password</p>
        <?php
    }

    /**
     * Get the selected form IDs.
     */
    public function get_selected_form_ids() {
        $selected_forms = get_option('endesa_api_konecta_form_ids', array());
        return $selected_forms;
    }
}