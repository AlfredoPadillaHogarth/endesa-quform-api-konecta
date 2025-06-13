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
        $this->table_name = $wpdb->prefix . ENDESA_API_KONECTA_TABLE_NAME;
        $this->table_rows = array();
        $this->api_url = get_option('endesa_api_konecta_url', 'https://endesa-api-514081513771.europe-west1.run.app');
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
        // Check if token is expired
        $this->auth_token = get_option('endesa_api_konecta_auth_token');
        $this->token_expiry = get_option('endesa_api_konecta_token_expiry');
        /*
        if ($this->auth_token) {
            // Token is valid
            return;
        }
        */

        // Token is expired or not set, request a new one
        $this->api_url = get_option('endesa_api_konecta_url', 'https://endesa-api-514081513771.europe-west1.run.app');
        $url = $this->api_url . '/auth/login';
        $username = get_option('endesa_api_username', 'endesa2025');
        $password = get_option('endesa_api_password', 'S3cr3t@.2@2@25');

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(array(
                'username' => $username,
                'password' => $password
            )),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $http_code = (int)$http_code;
        $response = json_decode($response, true);
        
        if ($http_code == 200 || $http_code == 201) {
            // Updated to check for both token and access_token in response
            $this->auth_token = isset($response['accessToken']) ? $response['accessToken'] : '';
            
            // Store the token in WP Options
            update_option('endesa_api_konecta_auth_token', $this->auth_token);
            curl_close($curl);    
            return $this->auth_token;
        } else {
            $this->auth_token = '';
            $this->token_expiry = '';
            $error_message = curl_error($curl);
            $this->insert_submission('N/A', array(), 'Error getting auth token: ' . $error_message . ' Response: ' . json_encode($response), $http_code, false);
            curl_close($curl);
            return false;
        }
    }

    /**
     * Get almost empty form data for submissions.
     * @return array
     */
    public function get_base_form_data() {
        return array(
            'payload' => array(
                'surname' => '',
                'name' => '',
                'cnae' => '0',  // Default value, not empty
                'document_type' => 'DNI',
                'document_number' => '',
                'language' => 'es',
                'phone' => '+34',
                'phone2' => '0',  // Default value, not empty
                'email' => '',
                'date' => date('Y-m-d'),
                'supply_address' => '0',  // Default value, not empty
                'stairs' => '0',  // Default value, not empty
                'flat' => '0',  // Default value, not empty
                'door' => '0',  // Default value, not empty
                'cp' => '0',  // Default value, not empty
                'town' => '0',  // Default value, not empty
                'province' => '0',  // Default value, not empty
                'other_address' => '0',  // Default value, not empty
                'cups' => '',
                'fee' => '0',  // Default value, not empty
                'stretch' => '0',  // Default value, not empty
                'p1' => '0',  // Default value, not empty
                'p2' => '0',  // Default value, not empty
                'p3' => '0',  // Default value, not empty
                'p4' => '0',  // Default value, not empty
                'p5' => '0',  // Default value, not empty
                'p6' => '0',  // Default value, not empty
                'v' => '0',
                'iban' => '',
                'offer' => '0',  // Default value, not empty
                'time' => '00:00',  // Default value, not empty
                'id_lead' => '0',  // Default value, not empty
            )
        );
    }

    /**
     * Format and validate the payload data according to API requirements
     */
    private function format_payload_data($data) {
        if (isset($data['payload'])) {
            // Format phone number
            if (!empty($data['payload']['phone']) && strpos($data['payload']['phone'], '+') !== 0) {
                $data['payload']['phone'] = '+' . ltrim($data['payload']['phone'], '+');
            }

            // Format IBAN
            if (!empty($data['payload']['iban'])) {
                $data['payload']['iban'] = str_replace(' ', '', $data['payload']['iban']);
            }

            // Ensure v is a string
            if (isset($data['payload']['v'])) {
                $data['payload']['v'] = (string)$data['payload']['v'];
            }

            // Validate document_type
            if (isset($data['payload']['document_type']) && $data['payload']['document_type'] === 'NIF') {
                $data['payload']['document_type'] = 'DNI';
            }

            // Ensure required fields are not empty
            $required_fields = [
                'cnae', 'supply_address', 'cp', 'town', 'province',
                'fee', 'stretch', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6',
                'time', 'offer'
            ];

            foreach ($required_fields as $field) {
                if (empty($data['payload'][$field])) {
                    $data['payload'][$field] = '0';
                }
            }

            // Special handling for time field
            if (empty($data['payload']['time'])) {
                $data['payload']['time'] = '00:00';
            }

            // Special handling for phone2
            if (empty($data['payload']['phone2'])) {
                $data['payload']['phone2'] = '0';
            }
        }
        return $data;
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
    public function send_post_request($url, $data, $lead_id='N/A') {
        try {
            $this->get_auth();
            if (!$this->auth_token) {
                $this->insert_submission($lead_id, $data['payload'], 'Error getting auth token', '401', false);
                return;
            }

            // Format and validate the data
            $data = $this->format_payload_data($data);

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->api_url . '/dev/v1/signatures',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->auth_token,
                    'Cookie: refreshToken=9ad502f4-9603-4908-b9ae-cbba29a5ed60'
                ),
            ));
            
            // For debugging
            error_log('Sending payload to ' . $this->api_url . '/dev/v1/signatures: ' . json_encode($data));
            
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            
            // For debugging
            error_log('Raw API Response: ' . $response);
            error_log('HTTP Code: ' . $http_code);

            $response = json_decode($response, true);
            curl_close($curl);

            $response_code = isset($response['code']) ? $response['code'] : $http_code;
            $response_message = '';

            if (isset($response['error']) && isset($response['error']['details'])) {
                // Handle validation errors
                $errors = array();
                foreach ($response['error']['details'] as $detail) {
                    $errors[] = $detail['message'];
                }
                $response_message = 'Validation errors: ' . implode('; ', $errors);
            } else {
                $response_message = isset($response['message']) ? $response['message'] : $this->get_http_code_description($http_code);
            }
            
            if ($http_code == 200 || $http_code == 201) {
                // Successful submission
                $this->insert_submission(
                    $lead_id,
                    $data['payload'],
                    'Success: ' . $response_message,
                    $response_code,
                    true
                );
                return true;
            } else {
                // Failed submission
                $this->insert_submission(
                    $lead_id,
                    $data['payload'],
                    'Error: ' . $response_message,
                    $response_code,
                    false
                );
                error_log('Endesa API Error: ' . $response_message);
                return false;
            }
        } catch (Exception $e) {
            $this->insert_submission(
                $lead_id,
                $data['payload'],
                'Error sending POST request: ' . $e->getMessage(),
                '500',
                false
            );
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
        try {
            // Validate inputs
            if (!is_object($form)) {
                error_log('Endesa API Error: Invalid form object');
                return $result;
            }

            $form_id = $form->getId();
            $form_ids = $this->get_selected_form_ids();
            
            // Check if we should process this form
            if (!in_array($form_id, $form_ids)) {
                return $result;
            }

            // Get base data structure and form values
            $base_data = $this->get_base_form_data();
            $form_data = $form->getValues();

            // Map the form fields to the base data structure
            foreach ($base_data['payload'] as $key => $value) {
                if (isset($this->forms_id_field_map[$form_id][$key]) && 
                    isset($form_data[$this->forms_id_field_map[$form_id][$key]])) {
                    $base_data['payload'][$key] = $form_data[$this->forms_id_field_map[$form_id][$key]];
                }
            }
            
            if (isset($base_data['payload']['id_lead'])) {
                $lead_id = $base_data['payload']['id_lead'];
                unset($base_data['payload']['id_lead']);
            }
  
            // For debugging
            error_log('Form Data to Send: ' . print_r($base_data, true));

            // Send the POST request to the API
            $this->send_post_request($this->api_url . '/dev/v1/signatures', $base_data, $lead_id);

            return $result;
        } catch (Exception $e) {
            error_log('Endesa API Error: ' . $e->getMessage());
            return $result;
        }
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
        $api_url = get_option('endesa_api_konecta_url', 'https://endesa-api-514081513771.europe-west1.run.app');
        ?>
        <select name="endesa_api_konecta_url">
            <option value="https://endesa-api-514081513771.europe-west1.run.app" <?php selected($api_url, 'https://endesa-api-514081513771.europe-west1.run.app'); ?>>Testing</option>
            <option value="https://endesa-api-514081513771.europe-west1.run.app" <?php selected($api_url, 'https://endesa-api-514081513771.europe-west1.run.app'); ?>>Production</option>
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