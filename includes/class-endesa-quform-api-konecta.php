<?php
// TODO: Cambiar la conexiona Salesforce por la de Konecta

class Endesa_API_Konecta
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
        $this->api_url = $this->get_api_url();
        $this->auth_token = '';
        $this->token_expiry = '';
        $this->forms_id_field_map = $this->load_id_field_map_json();
        
        // Add the options page to the admin menu
        add_action('admin_menu', array($this, 'add_options_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX action for form submission
        add_action('wp_ajax_endesa_submit_form', array($this, 'handle_ajax_submission'));
        add_action('wp_ajax_nopriv_endesa_submit_form', array($this, 'handle_ajax_submission'));
        
        // Add form hook handler to intercept all form submissions
        add_filter('quform_post_process', [$this, 'quform_hook_handler'], 10, 2);
        
        // Setup cron job
        $this->setup_cron();
    }

    // DATABASE MANAGEMENT FUNCTIONS
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
        $json = file_get_contents(ENDESA_API_PLUGIN_DIR . '/includes/form_fields_ids.json');
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
        $this->auth_token = get_option('endesa_api_auth_token');
        $this->token_expiry = get_option('endesa_api_token_expiry');
        if ($this->auth_token && $this->token_expiry > time()) {
            // Token is valid
            return;
        }

        // Token is expired or not set, request a new one
        $url = $this->api_url . '/tokencosdh';
        $username = get_option('endesa_api_username', 'rextzapie001');
        $password = get_option('endesa_api_password', '@tEMPORAL$2022');
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
          CURLOPT_URL => $this->api_url . '/tokencosdh',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => 'username=' . $username . '&password=' . $password . '&grant_type=password',
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic b0wyNllXMjNXUjBWMXRXYVU1Zkd4bktPS3lFYTpEQlhtRW9vd25helJXbXFJbGFqWTY0Wk9BMllh',
            'Cookie: incap_ses_197_3141923=a7KxHgIBhSdIYwoQqOK7AmHb82cAAAAAqXl8qwKKSCks2TC/0VKkcg==; incap_ses_197_3145005=3rXHIPIZlx5KQ9QPqOK7Akeh82cAAAAACtmBImdHakJZysqpdyBi9A==; nlbi_3141923=pR6DdSPts2mfhdFjOzeesgAAAADKZlt2Kes9ud2fsnBStp5u; nlbi_3145005=MDBFLF4kVDes4LEztt/DXgAAAAAjy3tVFQ0p6XqimNGTvekM; visid_incap_3141923=z/sN5At4S5eHXVSAB39wUR2e82cAAAAAQUIPAAAAAAArl7QLILQkHFJX9dmXriIN; visid_incap_3145005=bBdjuaWPQRus3da3YQ72Jkeh82cAAAAAQUIPAAAAAAC1aNre1VyNIBfMeq1H5JAv; CookieConsentPolicy=0:1; LSKey-c$CookieConsentPolicy=0:1'
          ),
        ));
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $response = json_decode($response, true);
        curl_close($curl);

        if ($http_code == 200) {
            $this->auth_token = $response['access_token'];
            $this->token_expiry = time() + $response['expires_in'];
            
            // Store the token on WP Options
            update_option('endesa_api_auth_token', $this->auth_token);
            update_option('endesa_api_token_expiry', $this->token_expiry);
            
            return $this->auth_token;
        } else {
            $this->auth_token = '';
            $this->token_expiry = '';
            return false;
        }
    }

    /**
     * Get almost empty form data for submissions.
     * @return array
     */
    public function get_base_form_data() {
        $soa_header = array(
            'soaId' => '123456789',
            'sourceApplication' => 'CRM',
            'serviceName' => 'LeadCreationService',
            'externalId' => '987654321',
            'IP' => '192.168.1.1',
            'client' => 'ENDESA',
            'user' => 'jdoe',
            'language' => 'ES',
            'version' => '1',
            'architectureVersion' => '2.0'
        );
        $leadRequest = array(
            'origen' => 'Web',
            'leadSource' => 'LandingPage',
            'website' => get_site_url(),
            'razonSocial' => '',
            'nombre' => '',
            'apellidos' => 'placeholder',
            'phone' => '',
            'email' => '',
            'yaCliente' => true,
            'rgpdAceptado' => true,
            'clienteCIF' => '',
            'productoSeleccionado' => '',
            'energia' => '',
            'sectorEmpresa' => '',
            'clienteCP' => '',
            'diaContactoPreferente' => '',
            'horaContactoPreferente' => '',
            'adjuntarFactura' => false,
            'dinamico1' => 'Valor1',
            'dinamico2' => 'Valor2',
            'dinamico3' => 'Valor3',
            'dinamico4' => 'Valor4',
            'dinamico5' => 'Valor5',
            'dinamico6' => 'Valor6',
            'dinamico7' => 'Valor7',
            'dinamico8' => 'Valor8',
            'dinamico9' => 'Valor9',
            'dinamico10' => 'Valor10',
            'facturaAdjunta' => false,
            'nombrefacturaAdjunta' => 'Factura.pdf',
            'idLead' => 'LEAD-20240212-001',
            'companyRequest' => 'Prueba'
        );

        $leadData = array(
            'leadRequest' => $leadRequest,
            'soaHeader' => $soa_header
        );
        
        return $leadData;
    }

    /**
     * Send a POST request to the API.
     * @param string $url
     * @param array $data
     */
    public function send_post_request($url, $data) {
        $this->get_auth();

        if (!$this->auth_token) {
            $this->insert_submission($data['leadRequest']['idLead'], $data['leadRequest'], 'Error getting auth token', '401', false);
            return;
        }
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->api_url . '/BusServ/bxmfexternalleadsbb-api/1.0.0/sendrosetta',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => '{
                "SOAHeader": {
                    "SOAId": "' .               $data['soaHeader']['soaId'] . '",
                    "SourceApplication": "' .   $data['soaHeader']['sourceApplication'] . '",
                    "ServiceName": "' .         $data['soaHeader']['serviceName'] . '",
                    "ExternalId": "' .          $data['soaHeader']['externalId'] . '",
                    "IP": "' .                  $data['soaHeader']['IP'] . '",
                    "Client": "' .              $data['soaHeader']['client'] . '",
                    "User": "' .                $data['soaHeader']['user'] . '",
                    "Language": "' .            $data['soaHeader']['language'] . '",
                    "Version": "' .             $data['soaHeader']['version'] . '",
                    "ArchitectureVersion": "' . $data['soaHeader']['architectureVersion'] . '"
                },
                "leadRequest": {
                    "Origen": "' .                 $data['leadRequest']['origen'] . '",
                    "LeadSource": "' .             $data['leadRequest']['leadSource'] . '",
                    "Website": "' .                $data['leadRequest']['website'] . '",
                    "RazonSocial": "' .            $data['leadRequest']['razonSocial'] . '",
                    "Nombre": "' .                 $data['leadRequest']['nombre'] . '",
                    "Apellidos": "' .              $data['leadRequest']['apellidos'] . '",
                    "Phone": "' .                  $data['leadRequest']['phone'] . '",
                    "Email": "' .                  $data['leadRequest']['email'] . '",
                    "YaCliente": ' .               json_encode($data['leadRequest']['yaCliente']) . ',
                    "RGPDAceptado": ' .            json_encode($data['leadRequest']['rgpdAceptado']) . ',
                    "ClienteCIF": "' .             $data['leadRequest']['clienteCIF'] . '",
                    "ProductoSeleccionado": "' .   $data['leadRequest']['productoSeleccionado'] . '",
                    "Energia": "' .                $data['leadRequest']['energia'] . '",
                    "SectorEmpresa": "' .          $data['leadRequest']['sectorEmpresa'] . '",
                    "ClienteCP": "' .              $data['leadRequest']['clienteCP'] . '",
                    "DiaContactoPreferente": "' .  $data['leadRequest']['diaContactoPreferente'] . '",
                    "HoraContactoPreferente": "' . $data['leadRequest']['horaContactoPreferente'] . '",
                    "AdjuntarFactura": ' .         json_encode($data['leadRequest']['adjuntarFactura']) . ',
                    "Dinamico1": "' .              $data['leadRequest']['dinamico1'] . '",
                    "Dinamico2": "' .              $data['leadRequest']['dinamico2'] . '",
                    "Dinamico3": "' .              $data['leadRequest']['dinamico3'] . '",
                    "Dinamico4": "' .              $data['leadRequest']['dinamico4'] . '",
                    "Dinamico5": "' .              $data['leadRequest']['dinamico5'] . '",
                    "Dinamico6": "' .              $data['leadRequest']['dinamico6'] . '",
                    "Dinamico7": "' .              $data['leadRequest']['dinamico7'] . '",
                    "Dinamico8": "' .              $data['leadRequest']['dinamico8'] . '",
                    "Dinamico9": "' .              $data['leadRequest']['dinamico9'] . '",
                    "Dinamico10": "' .             $data['leadRequest']['dinamico10'] . '",
                    "FacturaAdjunta": ' .          json_encode($data['leadRequest']['facturaAdjunta']) . ',
                    "IdLead": "' .                 $data['leadRequest']['idLead'] . '",
                    "CompanyRequest": "' .         $data['leadRequest']['companyRequest'] . '"
                }
            }',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->auth_token,
                'Cookie: incap_ses_197_3141923=a7KxHgIBhSdIYwoQqOK7AmHb82cAAAAAqXl8qwKKSCks2TC/0VKkcg==; incap_ses_197_3145005=3rXHIPIZlx5KQ9QPqOK7Akeh82cAAAAACtmBImdHakJZysqpdyBi9A==; nlbi_3141923=pR6DdSPts2mfhdFjOzeesgAAAADKZlt2Kes9ud2fsnBStp5u; nlbi_3145005=MDBFLF4kVDes4LEztt/DXgAAAAAjy3tVFQ0p6XqimNGTvekM; visid_incap_3141923=z/sN5At4S5eHXVSAB39wUR2e82cAAAAAQUIPAAAAAAArl7QLILQkHFJX9dmXriIN; visid_incap_3145005=bBdjuaWPQRus3da3YQ72Jkeh82cAAAAAQUIPAAAAAAC1aNre1VyNIBfMeq1H5JAv'
            ),
        ));
            
        try {
            $response = json_decode(curl_exec($curl))->result;
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($response->respuesta === 'OK' && $response->errorMessage === null && $response->errorCode === null) {
                // SUCCESS
                $this->insert_submission($data['leadRequest']['idLead'], $data, json_encode($response), $http_code, true);
            } else {
                // ERROR
                if ($response->errorMessage === null && $response->errorCode === null) {
                    if ($response === null || $response === '') {
                        $this->insert_submission($data['leadRequest']['idLead'], $data, $this->get_http_code_description($http_code), $http_code, false);
                    } else {
                        $this->insert_submission($data['leadRequest']['idLead'], $data, json_encode($response), $http_code, false);
                    }
                } else {
                    $this->insert_submission($data['leadRequest']['idLead'], $data, $response->errorMessage, $response->errorCode, false);
                }
            }
            curl_close($curl);
        } catch (Exception $e) {
            // OTRO ERROR
            $this->insert_submission($data['leadRequest']['idLead'], $data, $e->getMessage(), $response->errorCode, false);
        } finally {
            // Close the cURL session
            curl_close($curl);
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
    
            // Get form data safely
            $entry_id = $form->getEntryId();
            $form_data = $form->getValues();
            
            // Initialize entry data array with default values
            $entry_data = array(
                'id_lead' => '',
                'landing' => '',
                'tarifa' => '',
                'sector' => '',
                'cp' => '',
                'soporte' => '',
                'es_cliente' => false,
                'telefono' => '',
                'email' => '',
                'nombre' => '',
                'cif' => '',
                'id_agente' => '',
                'agente' => '',
                'factura' => '',
                'adjuntarFactura' => false
            );
    
            // Handle two-step form if applicable
            if ($form_id == 20) {
                $first_form_data = $this->handle_two_step_form($entry_id - 1);
                if ($first_form_data) {
                    foreach ($entry_data as $key => $value) {
                        if (isset($first_form_data[$this->forms_id_field_map['18'][$key]])) {
                            $entry_data[$key] = $first_form_data[$this->forms_id_field_map['18'][$key]];
                        }
                    }
                }
            }
    
            // Map current form data
            foreach ($entry_data as $key => $value) {
                if (isset($form_data[$this->forms_id_field_map[$form_id][$key]])) {
                    $entry_data[$key] = $form_data[$this->forms_id_field_map[$form_id][$key]];
                }
            }
    
            // Handle file upload if present
            $base64Content = '';
            if (!empty($entry_data['factura']) && is_array($entry_data['factura'])) {
                try {
                    $filePath = stripslashes($entry_data['factura'][0]['path']);
                    // On the path, we need to replace the current filename with the Lead ID, presserving the file extension
                    $filePath = str_replace($entry_data['factura'][0]['name'], $entry_data['id_lead'] . '.' . pathinfo($entry_data['factura'][0]['name'], PATHINFO_EXTENSION), $filePath);
                    $fileContent = file_get_contents($filePath);
                    $base64Content = base64_encode($fileContent);
                } catch (Exception $e) {
                    error_log('Endesa API File Error: ' . $e->getMessage());
                }
            }

            // Update the data array with file information if present
            if (!empty($base64Content)) {
                $data['leadRequest']['adjuntarFactura'] = true;
                $data['leadRequest']['facturaAdjunta'] = $base64Content;
                if (isset($form_data[$this->forms_id_field_map[$form_id]['factura']][0]['name'])) {
                    $data['leadRequest']['nombrefacturaAdjunta'] = basename($form_data[$this->forms_id_field_map[$form_id]['factura']][0]['name']);
                }
            } else {
                $data['leadRequest']['adjuntarFactura'] = false;
                $data['leadRequest']['facturaAdjunta'] = '';
                $data['leadRequest']['nombrefacturaAdjunta'] = '';
            }
    
            // Prepare API data
            $data = $this->get_base_form_data();
    
            // Split name into first and last name
            $name_parts = !empty($entry_data['nombre']) ? explode(' ', $entry_data['nombre']) : array('', '');
            $first_name = array_shift($name_parts);
            $last_name = implode(' ', $name_parts);
            if ($last_name == '' || empty($last_name) || !isset($last_name)) {
                $last_name = 'placeholder';
            }
    
            // Map data to API format
            $data['leadRequest']['idLead'] = $entry_data['id_lead'];
            $data['leadRequest']['phone'] = $entry_data['telefono'];
            $data['leadRequest']['yaCliente'] = $entry_data['es_cliente'] == 'NO'? false : true;
            $data['leadRequest']['clienteCIF'] = $entry_data['cif'];
            $data['leadRequest']['sectorEmpresa'] = $entry_data['sector'];
            $data['leadRequest']['productoSeleccionado'] = $entry_data['tarifa'];
            $data['leadRequest']['clienteCP'] = $entry_data['cp'];
            $data['leadRequest']['companyRequest'] = $entry_data['soporte'];
            $data['leadRequest']['leadSource'] = $entry_data['landing'];
            $data['leadRequest']['email'] = $entry_data['email'];
            $data['leadRequest']['nombre'] = $first_name;
            $data['leadRequest']['apellidos'] = $last_name;
            $data['leadRequest']['dinamico1'] = $entry_data['id_agente'];
    
            if (!empty($base64Content)) {
                $data['leadRequest']['adjuntarFactura'] = true;
                $data['leadRequest']['facturaAdjunta'] = $base64Content;
                $data['leadRequest']['nombrefacturaAdjunta'] = basename($entry_data['factura'][0]['name']);
            }

            // Is first step
            if ($form_id == 18) {
                $this->insert_submission($entry_data['id_lead'], $data, "First step", '000', false);
                return;
            }
    
            // Send to API
            $this->send_post_request($this->api_url . '/BusServ/bxmfexternalleadsbb-api/1.0.0/sendrosetta', $data);
    
            return $result;
        } catch (Exception $e) {
            error_log('Endesa API Fatal Error: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * Handle the two-step form submission.
     * Recieves an entry_id and querys the DB for the entry data.
     * @param int $entry_id
     * @return array|bool
     */
    private function handle_two_step_form($entry_id) {
        $enrey_data = array();

        global $wpdb;
        $query = "SELECT * FROM d3s4rr0ll0_quform_entry_data WHERE entry_id = " . $entry_id . ";";
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            return false;
        }
        
        foreach ($results as $row) {
            $entry_data['quform_18_' . $row['element_id']] = $row['value'];
        }
        
        return $entry_data;
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

    //
    // CRON JOB FUNCTIONS
    //
    /**
     * Setup the cron job.
     */
    private function setup_cron() {
        // Schedule the event if it's not already scheduled
        if (!wp_next_scheduled('endesa_api_cron_hook')) {
            wp_schedule_event(time(), 'hourly', 'endesa_api_cron_hook');
        }

        // Add the action hook for the cron job
        add_action('endesa_api_cron_hook', array($this, 'do_cron_post'));
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
    // AJAX FUNCTIONS
    //
    /**
     * Enqueue scripts for the ajax action.
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'endesa-api-ajax', 
            plugins_url('js/ajax-quform.js', __FILE__), 
            array('jquery'), 
            '1.0', 
            true
        );
        wp_localize_script(
            'endesa-api-ajax', 
            'endesaApiAjax', 
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('endesa_api_nonce')
            )
        );
    }

    /**
     * Retrieves the necesary data from DB according to the lead ID and sends it to the API.
     */
    public function handle_ajax_submission() {
        // Verify nonce first
        if (!check_ajax_referer('endesa_api_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => 'Invalid security token'
            ));
        }

        // Get and validate lead_id
        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field($_POST['lead_id']) : '';
        if (empty($lead_id)) {
            wp_send_json_error(array(
                'message' => 'Lead ID is required'
            ));
        }

        try {
            // Get the form data from database
            global $wpdb;
            $query = $wpdb->prepare(
                "SELECT form_data FROM {$this->table_name} 
                WHERE lead_id = %s 
                AND successfull_sent = false
                ORDER BY created_at DESC 
                LIMIT 1",
                $lead_id
            );

            $result = $wpdb->get_row($query);
            if (!$result) {
                wp_send_json_error(array(
                    'message' => 'No pending submission found for this lead'
                ));
            }

            $data = json_decode($result->form_data, true);
            
            // Send to API
            $api_response = $this->send_post_request(
                $this->api_url . '/BusServ/bxmfexternalleadsbb-api/1.0.0/sendrosetta', 
                $data
            );

            wp_send_json_success(array(
                'message' => 'Form data sent successfully',
                'response' => $api_response
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error processing request',
                'error' => $e->getMessage()
            ));
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
            'Endesa API Settings',
            'Endesa API',
            'manage_options',
            'endesa-api-settings',
            array($this, 'render_options_page')
        );
    }

    /**
     * Add the settings link to the plugin page.
     * @param array $links
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=endesa-api-settings">Ajustes</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Register the settings for the options page.
     */
    public function register_settings() {
        register_setting('endesa_api_options', 'endesa_api_form_ids');
        register_setting('endesa_api_options', 'endesa_api_url');
        register_setting('endesa_api_options', 'endesa_api_username');
        register_setting('endesa_api_options', 'endesa_api_password');

        add_settings_section(
            'endesa_api_main_section',
            'Form Settings',
            null,
            'endesa-api-settings'
        );

        add_settings_field(
            'endesa_api_form_ids',
            'Selected Form IDs',
            array($this, 'render_form_ids_field'),
            'endesa-api-settings',
            'endesa_api_main_section'
        );

        add_settings_field(
            'endesa_api_url',
            'API URL',
            array($this, 'render_api_url_field'),
            'endesa-api-settings',
            'endesa_api_main_section'
        );

        add_settings_field(
            'endesa_api_username',
            'API Username',
            array($this, 'render_username_field'),
            'endesa-api-settings',
            'endesa_api_main_section'
        );

        add_settings_field(
            'endesa_api_password',
            'API Password',
            array($this, 'render_password_field'),
            'endesa-api-settings',
            'endesa_api_main_section'
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
                settings_fields('endesa_api_options');
                do_settings_sections('endesa-api-settings');
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
        $api_url = get_option('endesa_api_url', 'https://gdidmz-qa.endesa.es/PRE');
        ?>
        <select name="endesa_api_url">
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
        $selected_forms = get_option('endesa_api_form_ids', array());
        
        // Get all Quform forms
        $forms = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}quform_forms", ARRAY_A);
        
        if (empty($forms)) {
            echo '<p>No Quform forms found.</p>';
            return;
        } ?>

        <select name="endesa_api_form_ids[]" multiple="multiple" style="min-width: 300px; min-height: 150px;">
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
        $username = get_option('endesa_api_username', 'rextzapie001');
        ?>
        <input type="text" name="endesa_api_username" value="<?php echo esc_attr($username); ?>" style="min-width: 300px;">
        <p class="description">Enter your API username</p>
        <?php
    }

    /**
     * Render the password field.
     */
    public function render_password_field() {
        $password = get_option('endesa_api_password', '@tEMPORAL$2022');
        ?>
        <input type="password" name="endesa_api_password" value="<?php echo esc_attr($password); ?>" style="min-width: 300px;">
        <p class="description">Enter your API password</p>
        <?php
    }

    /**
     * Get the API URL.
     */
    public function get_api_url() {
        $api_url = get_option('endesa_api_url', 'https://gdidmz-qa.endesa.es/PRE');
        return $api_url;
    }

    /**
     * Get the selected form IDs.
     */
    public function get_selected_form_ids() {
        $selected_forms = get_option('endesa_api_form_ids', array());
        return $selected_forms;
    }
}