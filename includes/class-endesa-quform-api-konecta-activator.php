<?php

class Endesa_Quform_API_Konecta_Activator 
{
    public static function activate() {
        self::maybe_create_table();
    }

    private static function maybe_create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . ENDESA_API_KONECTA_TABLE_NAME;

        // Check if the table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            self::create_table();
        }
    }

    private static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . ENDESA_API_KONECTA_TABLE_NAME;

        // SQL to create the table
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            lead_id varchar(255) NOT NULL,
            form_data longtext NOT NULL,
            response longtext NOT NULL,
            response_code varchar(255) DEFAULT NULL,
            successfull_sent boolean DEFAULT false NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Include the WordPress upgrade file
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}