<?php
/**
 * Admin Interface for Legal Document Automation
 * 
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_init', array($this, 'initSettings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        
        // Form settings integration
        add_filter('gform_form_settings_fields', array($this, 'addFormSettings'), 10, 2);
        add_filter('gform_pre_form_settings_save', array($this, 'saveFormSettings'));

        // AJAX handlers
        add_action('wp_ajax_lda_clear_logs', array($this, 'handleAjaxClearLogs'));
        add_action('wp_ajax_lda_validate_template', array($this, 'handleAjaxValidateTemplate'));
        add_action('wp_ajax_lda_get_logs', array($this, 'handleAjaxGetLogs'));
        add_action('wp_ajax_lda_delete_template', array($this, 'handleAjaxDeleteTemplate'));
        add_action('wp_ajax_lda_test_template', array($this, 'handleAjaxTestTemplate'));
        add_action('wp_ajax_lda_test_processing', array($this, 'handleAjaxTestProcessing'));
        add_action('wp_ajax_lda_test_modifier', array($this, 'handleAjaxTestModifier'));
        add_action('wp_ajax_lda_test_email', array($this, 'handleAjaxTestEmail'));
        add_action('wp_ajax_lda_test_gdrive', array($this, 'handleAjaxTestGoogleDrive'));
        add_action('wp_ajax_lda_test_pdf', array($this, 'handleAjaxTestPdf'));
        add_action('wp_ajax_lda_get_templates', array($this, 'handleAjaxGetTemplates'));
        add_action('wp_ajax_lda_debug_template', array($this, 'handleAjaxDebugTemplate'));
        add_action('wp_ajax_lda_upload_gdrive_credentials', array($this, 'handleAjaxUploadGDriveCredentials'));

        // Custom admin styles
        add_action('admin_head', array($this, 'adminIconStyles'));
    }

    /**
     * Add custom CSS to the admin head to style the menu icon.
     */
    public function adminIconStyles() {
        ?>
        <style>
            #toplevel_page_lda-settings .wp-menu-image.dashicons-before::before {
                color: #FF1493; /* DeepPink */
            }
            /* Correctly target the active state */
            #toplevel_page_lda-settings.current .wp-menu-image.dashicons-before::before,
            #toplevel_page_lda-settings.wp-has-current-submenu .wp-menu-image.dashicons-before::before,
            #toplevel_page_lda-settings:hover .wp-menu-image.dashicons-before::before {
                color: #fed919; /* Yellow for active/hover icon */
            }
            #toplevel_page_lda-settings.current a.menu-top,
            #toplevel_page_lda-settings.wp-has-current-submenu a.menu-top {
                background: #FF1493; /* Pink background for active item */
            }
            #toplevel_page_lda-settings.current a.menu-top .wp-menu-name,
            #toplevel_page_lda-settings.wp-has-current-submenu a.menu-top .wp-menu-name {
                color: white; /* White text for active item */
            }
        </style>
        <?php
    }

    /**
     * Handle AJAX request to clear logs
     */
    public function handleAjaxClearLogs() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to clear logs.', 'legal-doc-automation'));
        }

        if (LDA_Logger::clearLogs()) {
            wp_send_json_success(__('Logs cleared successfully.', 'legal-doc-automation'));
        } else {
            wp_send_json_error(__('Failed to clear logs. The log file might not be writable.', 'legal-doc-automation'));
        }
    }

    /**
     * Handle AJAX template validation
     */
    public function handleAjaxValidateTemplate() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'lda_admin_nonce')) {
            wp_die(__('Security check failed', 'legal-doc-automation'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'legal-doc-automation'));
        }

        try {
            $template_file = sanitize_text_field($_POST['template_file']);
            LDA_Logger::log("Starting validation for template: " . $template_file);

            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template_file;

            $settings = get_option('lda_settings', array());
            $merge_engine = new LDA_MergeEngine($settings);
            $result = $merge_engine->validateTemplate($template_path);

            if ($result['success']) {
                LDA_Logger::log("Template validation successful for: " . $template_file);
            } else {
                LDA_Logger::warning("Template validation failed for: " . $template_file . ". Reason: " . $result['message']);
            }

            wp_send_json_success($result);

        } catch (Exception $e) {
            LDA_Logger::error("Exception during template validation: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request to get logs
     */
    public function handleAjaxGetLogs() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to view logs.', 'legal-doc-automation'));
        }

        $logs_html = $this->getRecentLogsHTML();

        wp_send_json_success($logs_html);
    }

    /**
     * Handle AJAX request to delete a template file.
     */
    public function handleAjaxDeleteTemplate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to delete templates.', 'legal-doc-automation'));
        }

        if (empty($_POST['template'])) {
            wp_send_json_error(__('No template filename provided.', 'legal-doc-automation'));
        }

        $filename = sanitize_file_name($_POST['template']);
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        $filepath = realpath($template_folder . $filename);

        // Security check: ensure the file is within the templates directory
        if (!$filepath || strpos($filepath, realpath($template_folder)) !== 0) {
            wp_send_json_error(__('Invalid file path or template does not exist.', 'legal-doc-automation'));
        }
        
        if (file_exists($filepath)) {
            if (unlink($filepath)) {
                LDA_Logger::log("Template deleted: " . $filename);
                wp_send_json_success(__('Template deleted successfully.', 'legal-doc-automation'));
            } else {
                LDA_Logger::error("Failed to delete template: " . $filename);
                wp_send_json_error(__('Could not delete the template. Please check file permissions.', 'legal-doc-automation'));
            }
        } else {
            wp_send_json_error(__('Template not found.', 'legal-doc-automation'));
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueAdminScripts($hook_suffix) {
        // The hook_suffix check was too restrictive, preventing JS from loading.
        // A better long-term solution might be to find the exact hook, but for now,
        // we will enqueue the scripts and styles on all admin pages to ensure functionality.
        
        wp_enqueue_script('lda-admin-js', LDA_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), LDA_VERSION, true);
        wp_localize_script('lda-admin-js', 'lda_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lda_admin_nonce'),
            'strings' => array(
                'testing' => __('Testing...', 'legal-doc-automation'),
                'validating' => __('Validating...', 'legal-doc-automation'),
                'success' => __('Success!', 'legal-doc-automation'),
                'error' => __('Error:', 'legal-doc-automation'),
                'confirm_delete' => __('Are you sure you want to delete this template?', 'legal-doc-automation')
            )
        ));
        
        wp_enqueue_style('lda-google-fonts', 'https://fonts.googleapis.com/css2?family=Raleway:wght@400;700&display=swap', false);
        wp_enqueue_style('lda-admin-css', LDA_PLUGIN_URL . 'assets/css/admin.css', array('lda-google-fonts'), LDA_VERSION);
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        add_menu_page(
            __('A Legal Documents', 'legal-doc-automation'),
            __('Doc Automation', 'legal-doc-automation'),
            'manage_options',
            'lda-settings',
            array($this, 'settingsPage'),
            'dashicons-media-document'
        );
    }
    
    /**
     * Enhanced settings page with tabbed interface
     */
    public function settingsPage() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap lda-admin-wrap">
            <h1><?php _e('A Legal Documents Settings', 'legal-doc-automation'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=lda-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=templates" class="nav-tab <?php echo $active_tab == 'templates' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Templates', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=email" class="nav-tab <?php echo $active_tab == 'email' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Email', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=gdrive" class="nav-tab <?php echo $active_tab == 'gdrive' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Google Drive', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=merge-tags" class="nav-tab <?php echo $active_tab == 'merge-tags' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Merge Tags', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=testing" class="nav-tab <?php echo $active_tab == 'testing' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Testing & Debug', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Activity Logs', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=status" class="nav-tab <?php echo $active_tab == 'status' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('System Status', 'legal-doc-automation'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'templates':
                        $this->showTemplatesTab();
                        break;
                    case 'email':
                        $this->showEmailTab();
                        break;
                    case 'gdrive':
                        $this->showGoogleDriveTab();
                        break;
                    case 'merge-tags':
                        $this->showMergeTagsTab();
                        break;
                    case 'testing':
                        $this->showTestingTab();
                        break;
                    case 'logs':
                        $this->showLogsTab();
                        break;
                    case 'status':
                        $this->showStatusTab();
                        break;
                    default:
                        $this->showGeneralTab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show general settings tab
     */
    private function showGeneralTab() {
        ?>
        <form method="post" action="options.php" class="lda-settings-form">
            <?php
            settings_fields('lda_settings');
            do_settings_sections('lda_general');
            submit_button();
            ?>
        </form>
        <?php
    }
    
    /**
     * Show templates management tab
     */
    private function showTemplatesTab() {
        // This function doesn't need to get the template folder anymore,
        // as the functions it calls will get it directly.
        ?>
        <div class="lda-templates-section">
            <h2><?php _e('Template Management', 'legal-doc-automation'); ?></h2>

            <div class="lda-card lda-intro">
                <p><?php _e('This tab is for managing your .docx templates. Upload new templates, validate their syntax, and see a list of all available templates. The merge tags in these documents will be replaced with data from your Gravity Forms submissions to generate the final documents.', 'legal-doc-automation'); ?></p>
            </div>
            
            <!-- Template Upload -->
            <div class="lda-card">
                <h3><?php _e('Upload New Template', 'legal-doc-automation'); ?></h3>
                <form method="post" enctype="multipart/form-data" action="">
                    <?php wp_nonce_field('lda_upload_template', 'lda_upload_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Template File', 'legal-doc-automation'); ?></th>
                            <td>
                                <input type="file" name="template_file" accept=".docx" required>
                                <p class="description">
                                    <?php _e('Upload DOCX files with Webmerge-compatible syntax.', 'legal-doc-automation'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Upload Template', 'legal-doc-automation')); ?>
                </form>
                <?php $this->handleTemplateUpload(); ?>
            </div>
            
            <!-- Template Actions Explanation -->
            <div class="lda-card">
                <h3><?php _e('Template Actions', 'legal-doc-automation'); ?></h3>
                <p><?php _e('For each uploaded template, you have the following actions:', 'legal-doc-automation'); ?></p>
                <ul>
                    <li><strong><?php _e('Validate:', 'legal-doc-automation'); ?></strong> <?php _e('Checks the template for correct merge tag syntax. This helps prevent errors during document generation.', 'legal-doc-automation'); ?></li>
                    <li><strong><?php _e('Test:', 'legal-doc-automation'); ?></strong> <?php _e('(Coming Soon) Allows you to run a test generation with sample data to see the output.', 'legal-doc-automation'); ?></li>
                    <li><strong><?php _e('Delete:', 'legal-doc-automation'); ?></strong> <?php _e('Permanently removes the template file.', 'legal-doc-automation'); ?></li>
                </ul>
            </div>

            <!-- Template List -->
            <div class="lda-card">
                <h3><?php _e('Existing Templates', 'legal-doc-automation'); ?></h3>
                <?php $this->displayTemplatesList(); ?>
            </div>
            
            <!-- Webmerge Syntax Guide -->
            <div class="lda-card">
                <h3><?php _e('Webmerge Syntax Reference', 'legal-doc-automation'); ?></h3>
                <div class="lda-syntax-guide">
                    <h4><?php _e('Basic Usage:', 'legal-doc-automation'); ?></h4>
                    <ul>
                        <li><code>{$FieldName}</code> - <?php _e('Basic field replacement', 'legal-doc-automation'); ?></li>
                        <li><code>{$USR_Name|ucwords}</code> - <?php _e('Apply title case', 'legal-doc-automation'); ?></li>
                        <li><code>{$USR_ABN|phone_format:"%2 %3 %3 %3"}</code> - <?php _e('Format ABN', 'legal-doc-automation'); ?></li>
                        <li><code>{$Date|date_format:"d F Y"}</code> - <?php _e('Format date', 'legal-doc-automation'); ?></li>
                    </ul>
                    
                    <h4><?php _e('Conditional Logic:', 'legal-doc-automation'); ?></h4>
                    <ul>
                        <li><code>{if !empty($Field)}Content{/if}</code> - <?php _e('Show if field has value', 'legal-doc-automation'); ?></li>
                        <li><code>{if $Type == "Premium"}Premium content{else}Standard content{/if}</code></li>
                    </ul>
                    
                    <h4><?php _e('Repeating Sections:', 'legal-doc-automation'); ?></h4>
                    <ul>
                        <li><code>{repeat TeamMembers}Name: {$Name}, Role: {$Role}{/repeat}</code></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show email settings tab
     */
    private function showEmailTab() {
        ?>
        <form method="post" action="options.php" class="lda-settings-form">
            <?php
            settings_fields('lda_settings');
            do_settings_sections('lda_email');
            ?>
            
            <div class="lda-card">
                <h3><?php _e('Available Email Shortcodes', 'legal-doc-automation'); ?></h3>
                <p><?php _e('You can use these shortcodes in your email subject and message templates:', 'legal-doc-automation'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Shortcode', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Description', 'legal-doc-automation'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>{FormTitle}</code></td>
                            <td><?php _e('The title of the form that was submitted', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserFirstName}</code></td>
                            <td><?php _e('First name of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserLastName}</code></td>
                            <td><?php _e('Last name of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserEmail}</code></td>
                            <td><?php _e('Email address of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{SiteName}</code></td>
                            <td><?php _e('Name of your WordPress site', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{CurrentDate}</code></td>
                            <td><?php _e('Current date (YYYY-MM-DD format)', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_docx_link}</code></td>
                            <td><?php _e('Google Drive link to view the DOCX document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_pdf_link}</code></td>
                            <td><?php _e('Google Drive link to view the PDF document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_folder_link}</code></td>
                            <td><?php _e('Google Drive link to the user\'s document folder', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_docx_download}</code></td>
                            <td><?php _e('Direct download link for the DOCX document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_pdf_download}</code></td>
                            <td><?php _e('Direct download link for the PDF document', 'legal-doc-automation'); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p><strong><?php _e('Note:', 'legal-doc-automation'); ?></strong> <?php _e('Documents are automatically attached to emails. Google Drive links are only available if Google Drive integration is enabled.', 'legal-doc-automation'); ?></p>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Email Test', 'legal-doc-automation'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Email Address', 'legal-doc-automation'); ?></th>
                        <td>
                            <input type="email" id="test-email" class="regular-text" placeholder="test@example.com">
                            <button type="button" id="send-test-email" class="button"><?php _e('Send Test Email', 'legal-doc-automation'); ?></button>
                            <div id="email-test-result"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Show Google Drive settings tab
     */
    private function showGoogleDriveTab() {
        ?>
        <form method="post" action="options.php" class="lda-settings-form">
            <?php
            settings_fields('lda_settings');
            do_settings_sections('lda_gdrive');
            ?>
            
            <div class="lda-card">
                <h3><?php _e('Google Drive Status', 'legal-doc-automation'); ?></h3>
                <?php $this->displayGoogleDriveStatus(); ?>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Show testing and debugging tab
     */
    private function showTestingTab() {
        ?>
        <div class="lda-testing-section">
            <h2><?php _e('Testing & Debugging Tools', 'legal-doc-automation'); ?></h2>
            
            <!-- Template Validation -->
            <div class="lda-card">
                <h3><?php _e('Template Validation', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Validate templates for Webmerge syntax errors and compatibility.', 'legal-doc-automation'); ?></p>
                <div class="template-validation-tool">
                    <select id="template-select">
                        <option value=""><?php _e('Select a template...', 'legal-doc-automation'); ?></option>
                        <?php $this->populateTemplateOptions(); ?>
                    </select>
                    <button type="button" id="validate-template" class="button"><?php _e('Validate Template', 'legal-doc-automation'); ?></button>
                    <div id="validation-results"></div>
                </div>
            </div>
            
            <!-- Modifier Testing -->
            <div class="lda-card">
                <h3><?php _e('Modifier Testing', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Test individual modifier functions with sample data.', 'legal-doc-automation'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Expression', 'legal-doc-automation'); ?></th>
                        <td>
                            <input type="text" id="modifier-expression" class="regular-text" placeholder="{$modifier}" value="{$TestField|ucwords}">
                            <p class="description"><?php _e('Examples: ucwords, upper, phone_format:"%3 %3 %4", date_format:"d F Y"', 'legal-doc-automation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Sample Data (JSON)', 'legal-doc-automation'); ?></th>
                        <td>
                            <textarea id="sample-data" rows="5" class="large-text">{"TestField": "hello world", "TestNumber": "123.45", "TestDate": "2025-09-04"}</textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Result', 'legal-doc-automation'); ?></th>
                        <td>
                            <button type="button" id="test-modifier" class="button"><?php _e('Test Modifier', 'legal-doc-automation'); ?></button>
                            <div id="modifier-result"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Document Processing Test -->
            <div class="lda-card">
                <h3><?php _e('Full Processing Test', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Test complete template processing with sample data.', 'legal-doc-automation'); ?></p>
                <div class="processing-test-tool">
                    <select id="test-template-select">
                        <option value=""><?php _e('Select a template...', 'legal-doc-automation'); ?></option>
                        <?php $this->populateTemplateOptions(); ?>
                    </select>
                    <button type="button" id="test-processing" class="button"><?php _e('Test Processing', 'legal-doc-automation'); ?></button>
                    <div id="processing-results"></div>
                </div>
            </div>
            
            <!-- Debug Settings -->
            <form method="post" action="options.php" class="lda-settings-form">
                <?php
                settings_fields('lda_settings');
                do_settings_sections('lda_debug');
                submit_button(__('Save Debug Settings', 'legal-doc-automation'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Show activity logs tab
     */
    private function showLogsTab() {
        ?>
        <div class="lda-logs-section">
            <h2><?php _e('Activity Logs', 'legal-doc-automation'); ?></h2>
            
            <div class="lda-card">
                <div class="log-controls">
                    <select id="log-level-filter">
                        <option value=""><?php _e('All Levels', 'legal-doc-automation'); ?></option>
                        <option value="ERROR"><?php _e('Errors Only', 'legal-doc-automation'); ?></option>
                        <option value="WARN"><?php _e('Warnings', 'legal-doc-automation'); ?></option>
                        <option value="INFO"><?php _e('Info', 'legal-doc-automation'); ?></option>
                        <option value="DEBUG"><?php _e('Debug', 'legal-doc-automation'); ?></option>
                    </select>
                    <select id="log-days-filter">
                        <option value="1"><?php _e('Last 24 hours', 'legal-doc-automation'); ?></option>
                        <option value="7" selected><?php _e('Last 7 days', 'legal-doc-automation'); ?></option>
                        <option value="30"><?php _e('Last 30 days', 'legal-doc-automation'); ?></option>
                    </select>
                    <button type="button" id="refresh-logs" class="button"><?php _e('Refresh', 'legal-doc-automation'); ?></button>
                    <button type="button" id="copy-logs" class="button button-primary"><?php _e('Copy Logs', 'legal-doc-automation'); ?></button>
                    <button type="button" id="clear-logs" class="button button-secondary"><?php _e('Clear Logs', 'legal-doc-automation'); ?></button>
                </div>
                
                <div id="log-entries">
                    <?php $this->displayRecentLogs(); ?>
                </div>
            </div>
            
            <!-- Log Statistics -->
            <div class="lda-card">
                <h3><?php _e('Log Statistics', 'legal-doc-automation'); ?></h3>
                <?php $this->displayLogStats(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show system status tab
     */
    private function showStatusTab() {
        ?>
        <div class="lda-status-section">
            <h2><?php _e('System Status', 'legal-doc-automation'); ?></h2>
            
            <!-- System Requirements -->
            <div class="lda-card">
                <h3><?php _e('System Requirements', 'legal-doc-automation'); ?></h3>
                <?php $this->displaySystemStatus(); ?>
            </div>
            
            <!-- Plugin Dependencies -->
            <div class="lda-card">
                <h3><?php _e('Plugin Dependencies', 'legal-doc-automation'); ?></h3>
                <?php $this->displayPluginStatus(); ?>
            </div>
            
            <!-- Directory Permissions -->
            <div class="lda-card">
                <h3><?php _e('Directory Permissions', 'legal-doc-automation'); ?></h3>
                <?php $this->displayDirectoryStatus(); ?>
            </div>
            
            <!-- Processing Statistics -->
            <div class="lda-card">
                <h3><?php _e('Processing Statistics (Last 30 Days)', 'legal-doc-automation'); ?></h3>
                <?php $this->displayProcessingStats(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Initialize plugin settings
     */
    public function initSettings() {
        register_setting('lda_settings', 'lda_settings', array(
            'sanitize_callback' => array($this, 'sanitizeSettings'),
            'default' => array(
                'enable_pdf_output' => 0,
                'google_drive_enabled' => 0,
                'google_drive_access_token' => '',
                'google_drive_folder_id' => '',
                'debug_mode' => 0,
            ),
        ));
        
        // General Settings
        add_settings_section('lda_general_section', __('General Settings', 'legal-doc-automation'), array($this, 'generalSectionCallback'), 'lda_general');
        
        add_settings_field('template_folder', __('Template Folder', 'legal-doc-automation'), array($this, 'templateFolderCallback'), 'lda_general', 'lda_general_section');
        add_settings_field('enable_pdf_output', __('Enable PDF Output', 'legal-doc-automation'), array($this, 'enablePdfCallback'), 'lda_general', 'lda_general_section');
        add_settings_field('pdf_engine', __('PDF Engine', 'legal-doc-automation'), array($this, 'pdfEngineCallback'), 'lda_general', 'lda_general_section');
        add_settings_field('pdf_quality', __('PDF Quality', 'legal-doc-automation'), array($this, 'pdfQualityCallback'), 'lda_general', 'lda_general_section');
        
        // Email Settings
        add_settings_section('lda_email_section', __('Email Settings', 'legal-doc-automation'), array($this, 'emailSectionCallback'), 'lda_email');
        
        add_settings_field('email_subject', __('Email Subject', 'legal-doc-automation'), array($this, 'emailSubjectCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('email_message', __('Email Message', 'legal-doc-automation'), array($this, 'emailMessageCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('from_email', __('From Email', 'legal-doc-automation'), array($this, 'fromEmailCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('from_name', __('From Name', 'legal-doc-automation'), array($this, 'fromNameCallback'), 'lda_email', 'lda_email_section');
        
        // Google Drive Settings
        add_settings_section('lda_gdrive_section', __('Google Drive Settings', 'legal-doc-automation'), array($this, 'gdriveSectionCallback'), 'lda_gdrive');
        
        add_settings_field('google_drive_enabled', __('Enable Google Drive', 'legal-doc-automation'), array($this, 'googleDriveEnabledCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_method', __('Google Drive Integration Method', 'legal-doc-automation'), array($this, 'googleDriveMethodCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_client_id', __('Google Drive Client ID', 'legal-doc-automation'), array($this, 'googleDriveClientIdCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_client_secret', __('Google Drive Client Secret', 'legal-doc-automation'), array($this, 'googleDriveClientSecretCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_credentials', __('Google Drive API Credentials', 'legal-doc-automation'), array($this, 'googleDriveCredentialsCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('use_your_drive_plugin', __('Use-your-Drive Plugin', 'legal-doc-automation'), array($this, 'useYourDrivePluginCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_access_token', __('Google Drive Access Token', 'legal-doc-automation'), array($this, 'googleDriveAccessTokenCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('google_drive_folder_id', __('Google Drive Folder ID', 'legal-doc-automation'), array($this, 'googleDriveFolderIdCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_root_folder', __('Root Folder Name', 'legal-doc-automation'), array($this, 'googleDriveRootFolderCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_folder_naming', __('Folder Naming Strategy', 'legal-doc-automation'), array($this, 'gdrivefolderNamingCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_filename_format', __('Filename Format', 'legal-doc-automation'), array($this, 'gdriveFilenameFormatCallback'), 'lda_gdrive', 'lda_gdrive_section');
        
        // Debug Settings
        add_settings_section('lda_debug_section', __('Debug Settings', 'legal-doc-automation'), array($this, 'debugSectionCallback'), 'lda_debug');
        
        add_settings_field('debug_mode', __('Debug Mode', 'legal-doc-automation'), array($this, 'debugModeCallback'), 'lda_debug', 'lda_debug_section');
        add_settings_field('max_processing_time', __('Max Processing Time (seconds)', 'legal-doc-automation'), array($this, 'maxProcessingTimeCallback'), 'lda_debug', 'lda_debug_section');
        add_settings_field('max_memory_usage', __('Max Memory Usage', 'legal-doc-automation'), array($this, 'maxMemoryUsageCallback'), 'lda_debug', 'lda_debug_section');
    }
    
    /**
     * Sanitize settings before saving
     */
    public function sanitizeSettings($input) {
        $sanitized = array();

        // When a checkbox is unchecked, it's not sent in the POST request.
        // We must check the raw POST data to see if the key exists.
        // If it doesn't exist, it means the box was unchecked, so we save 0.
        $sanitized['enable_pdf_output'] = isset($input['enable_pdf_output']) ? 1 : 0;
        $sanitized['google_drive_enabled'] = isset($input['google_drive_enabled']) ? 1 : 0;
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? 1 : 0;

        // Sanitize text and email fields
        $sanitized['email_subject'] = isset($input['email_subject']) ? sanitize_text_field($input['email_subject']) : '';
        $sanitized['email_message'] = isset($input['email_message']) ? sanitize_textarea_field($input['email_message']) : '';
        $sanitized['from_email'] = isset($input['from_email']) ? sanitize_email($input['from_email']) : '';
        $sanitized['from_name'] = isset($input['from_name']) ? sanitize_text_field($input['from_name']) : '';
        
        // Sanitize Google Drive settings
        $sanitized['google_drive_method'] = isset($input['google_drive_method']) ? sanitize_text_field($input['google_drive_method']) : 'native_api';
        $sanitized['google_drive_access_token'] = isset($input['google_drive_access_token']) ? sanitize_text_field($input['google_drive_access_token']) : '';
        $sanitized['google_drive_folder_id'] = isset($input['google_drive_folder_id']) ? sanitize_text_field($input['google_drive_folder_id']) : '';
        $sanitized['gdrive_root_folder'] = isset($input['gdrive_root_folder']) ? sanitize_text_field($input['gdrive_root_folder']) : 'LegalDocuments';
        $sanitized['gdrive_folder_naming'] = isset($input['gdrive_folder_naming']) ? sanitize_text_field($input['gdrive_folder_naming']) : 'business_name';
        $sanitized['gdrive_filename_format'] = isset($input['gdrive_filename_format']) ? sanitize_text_field($input['gdrive_filename_format']) : 'form_business_date';
        
        $sanitized['max_processing_time'] = isset($input['max_processing_time']) ? intval($input['max_processing_time']) : 300;
        $sanitized['max_memory_usage'] = isset($input['max_memory_usage']) ? sanitize_text_field($input['max_memory_usage']) : '512M';
        
        return $sanitized;
    }
    
    // Section Callbacks
    public function generalSectionCallback() {
        echo '<p>' . __('Configure general document automation settings.', 'legal-doc-automation') . '</p>';
    }
    
    public function emailSectionCallback() {
        $text = __('<strong>OPTIONAL:</strong> These settings control the <strong>content</strong> of the email that is automatically sent to the user after they submit a form. The final merged document is sent as an attachment.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('<strong>Important:</strong> If you leave these fields empty, the plugin will use default templates that include Google Drive links. The plugin will always send emails with document attachments.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('The recipient\'s email address is automatically determined from the form submission; you do not need to set it here.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('Use merge tags like {UserFirstName} or any form field label (e.g., {BusinessName}) in the Subject and Message fields to personalize the content.', 'legal-doc-automation');
        echo '<p>' . $text . '</p>';
    }
    
    public function gdriveSectionCallback() {
        echo '<p>' . __('Configure Google Drive integration settings. Files will be uploaded to Google Drive after document generation.', 'legal-doc-automation') . '</p>';
        echo '<p><strong>' . __('Integration Methods (in order of preference):', 'legal-doc-automation') . '</strong></p>';
        echo '<ol>';
        echo '<li><strong>OAuth Google Drive:</strong> Direct integration using access token and folder ID (Recommended)</li>';
        echo '<li><strong>Native Google Drive API:</strong> Direct integration using Google API credentials</li>';
        echo '<li><strong>Use-your-Drive Plugin:</strong> Integration via WP Cloud Plugins - Use-your-Drive</li>';
        echo '<li><strong>File-based Storage:</strong> Local storage with simulated Google Drive links</li>';
        echo '</ol>';
        echo '<p><strong>' . __('For OAuth Integration (Recommended):', 'legal-doc-automation') . '</strong></p>';
        echo '<ul>';
        echo '<li>Get your <strong>Access Token</strong> from your Google Drive application</li>';
        echo '<li>Get your <strong>Folder ID</strong> from the Google Drive folder URL</li>';
        echo '<li>Enter both values below to enable real Google Drive uploads</li>';
        echo '</ul>';
        echo '<p><strong>' . __('Can\'t find your Access Token?', 'legal-doc-automation') . '</strong></p>';
        echo '<ul>';
        echo '<li>Check your Google Drive app\'s settings or logs</li>';
        echo '<li>Look in browser Developer Tools (F12) → Network tab for API calls</li>';
        echo '<li><strong>OR</strong> leave these fields empty - the plugin will automatically use your Use-your-Drive plugin!</li>';
        echo '</ul>';
        echo '<p><strong>' . __('Recommended: Use Use-your-Drive Plugin', 'legal-doc-automation') . '</strong></p>';
        echo '<p>Since you already have the Use-your-Drive plugin installed and configured, you can simply:</p>';
        echo '<ol>';
        echo '<li>Leave the Access Token and Folder ID fields empty</li>';
        echo '<li>The plugin will automatically detect and use your Use-your-Drive plugin</li>';
        echo '<li>Files will be uploaded to your configured Google Drive account</li>';
        echo '<li>No additional setup required!</li>';
        echo '</ol>';
        echo '<p><em>' . __('For native API, upload your Google API credentials file to wp-content/uploads/lda-google-credentials.json', 'legal-doc-automation') . '</em></p>';
    }
    
    public function debugSectionCallback() {
        echo '<p>' . __('Debug and logging settings for troubleshooting.', 'legal-doc-automation') . '</p>';
    }
    
    // Field Callbacks
    public function templateFolderCallback() {
        $path = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        // Make the path more readable by replacing the absolute server path with a more user-friendly version.
        $display_path = str_replace(ABSPATH, '[...]/', $path);

        echo '<code>' . esc_html($display_path) . '</code>';
        echo '<p class="description">' . __('This folder is automatically created and used by the plugin. Please upload your templates via the \'Templates\' tab.', 'legal-doc-automation') . '</p>';
    }
    
    
    public function enablePdfCallback() {
        $options = get_option('lda_settings');
        $checked = isset($options['enable_pdf_output']) && $options['enable_pdf_output'] ? 'checked' : '';
        echo '<input type="checkbox" name="lda_settings[enable_pdf_output]" value="1" ' . $checked . '>';
        echo '<p class="description">' . __('Also generate PDF versions of documents (requires additional PDF library)', 'legal-doc-automation') . '</p>';
    }
    
    public function pdfEngineCallback() {
        $options = get_option('lda_settings');
        $selected = isset($options['pdf_engine']) ? $options['pdf_engine'] : 'auto';
        
        // Get available PDF engines
        $pdf_handler = new LDA_PDFHandler($options);
        $available_engines = $pdf_handler->getAvailableEngines();
        
        echo '<select name="lda_settings[pdf_engine]">';
        echo '<option value="auto"' . ($selected === 'auto' ? ' selected' : '') . '>' . __('Auto (Best Available)', 'legal-doc-automation') . '</option>';
        
        foreach ($available_engines as $engine => $name) {
            $is_selected = ($selected === $engine) ? ' selected' : '';
            echo '<option value="' . esc_attr($engine) . '"' . $is_selected . '>' . esc_html($name) . '</option>';
        }
        
        echo '</select>';
        echo '<p class="description">' . __('Choose the PDF engine to use for document conversion', 'legal-doc-automation') . '</p>';
        
        if (empty($available_engines)) {
            echo '<p class="description" style="color: #d63638;">' . __('No PDF engines are available. Please install DomPDF, TCPDF, or ensure PHPWord PDF writer is available.', 'legal-doc-automation') . '</p>';
        }
    }
    
    public function pdfQualityCallback() {
        $options = get_option('lda_settings');
        $selected = isset($options['pdf_quality']) ? $options['pdf_quality'] : 'high';
        
        echo '<select name="lda_settings[pdf_quality]">';
        echo '<option value="low"' . ($selected === 'low' ? ' selected' : '') . '>' . __('Low (Faster)', 'legal-doc-automation') . '</option>';
        echo '<option value="medium"' . ($selected === 'medium' ? ' selected' : '') . '>' . __('Medium (Balanced)', 'legal-doc-automation') . '</option>';
        echo '<option value="high"' . ($selected === 'high' ? ' selected' : '') . '>' . __('High (Best Quality)', 'legal-doc-automation') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('PDF generation quality (higher quality takes longer)', 'legal-doc-automation') . '</p>';
    }
    
    public function emailSubjectCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['email_subject']) ? $options['email_subject'] : 'Your legal document is ready - {FormTitle}';
        echo '<input type="text" name="lda_settings[email_subject]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function emailMessageCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['email_message']) ? $options['email_message'] : "Dear {UserFirstName},\n\nYour legal document \"{FormTitle}\" has been generated and is ready for your review.\n\nPlease find the completed document attached to this email.\n\nBest regards,\n{SiteName}";
        echo '<textarea name="lda_settings[email_message]" rows="8" class="large-text">' . esc_textarea($value) . '</textarea>';
    }
    
    public function fromEmailCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['from_email']) ? $options['from_email'] : get_option('admin_email');
        echo '<input type="email" name="lda_settings[from_email]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Leave empty to use WordPress default', 'legal-doc-automation') . '</p>';
    }
    
    public function fromNameCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['from_name']) ? $options['from_name'] : get_bloginfo('name');
        echo '<input type="text" name="lda_settings[from_name]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Leave empty to use site name', 'legal-doc-automation') . '</p>';
    }
    
    public function googleDriveEnabledCallback() {
        $options = get_option('lda_settings');
        $checked = isset($options['google_drive_enabled']) && $options['google_drive_enabled'] ? 'checked' : '';
        echo '<input type="checkbox" name="lda_settings[google_drive_enabled]" value="1" ' . $checked . '>';
        echo '<p class="description">' . __('Save generated documents to user\'s Google Drive folders', 'legal-doc-automation') . '</p>';
    }

    public function googleDriveMethodCallback() {
        $options = get_option('lda_settings');
        $method = isset($options['google_drive_method']) ? $options['google_drive_method'] : 'oauth';
        
        echo '<select name="lda_settings[google_drive_method]" id="gdrive_method" onchange="toggleGDriveFields()">';
        echo '<option value="oauth" ' . selected($method, 'oauth', false) . '>' . __('OAuth (Real Google Drive)', 'legal-doc-automation') . '</option>';
        echo '<option value="native_api" ' . selected($method, 'native_api', false) . '>' . __('Native Google Drive API', 'legal-doc-automation') . '</option>';
        echo '<option value="use_your_drive" ' . selected($method, 'use_your_drive', false) . '>' . __('Use-your-Drive Plugin', 'legal-doc-automation') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Choose how to connect to Google Drive. The form below will update to show only the relevant fields for your selection.', 'legal-doc-automation') . '</p>';
        
        // Add JavaScript to toggle fields
        echo '<script>
        function toggleGDriveFields() {
            var method = document.getElementById("gdrive_method").value;
            
            // Hide all method-specific sections
            var sections = ["native_api_section", "use_your_drive_section", "oauth_section"];
            sections.forEach(function(sectionId) {
                var section = document.getElementById(sectionId);
                if (section) {
                    section.style.display = "none";
                }
            });
            
            // Show the relevant section
            var targetSection = method + "_section";
            var targetElement = document.getElementById(targetSection);
            if (targetElement) {
                targetElement.style.display = "block";
            }
        }
        
        // Initialize on page load
        document.addEventListener("DOMContentLoaded", function() {
            toggleGDriveFields();
        });
        </script>';
    }

    public function googleDriveCredentialsCallback() {
        $options = get_option('lda_settings');
        $upload_dir = wp_upload_dir();
        $credentials_path = $upload_dir['basedir'] . '/lda-google-credentials.json';
        $credentials_exist = file_exists($credentials_path);
        
        echo '<div id="native_api_section" class="gdrive-method-section">';
        echo '<h4>' . __('Native Google Drive API Setup', 'legal-doc-automation') . '</h4>';
        
        if ($credentials_exist) {
            echo '<div class="notice notice-success inline"><p><strong>✓</strong> ' . __('Google Drive API credentials file found and ready to use.', 'legal-doc-automation') . '</p></div>';
            echo '<p><strong>' . __('Current credentials file:', 'legal-doc-automation') . '</strong> <code>' . $credentials_path . '</code></p>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>⚠</strong> ' . __('Google Drive API credentials file not found.', 'legal-doc-automation') . '</p></div>';
        }
        
        echo '<h5>' . __('How to set up Google Drive API:', 'legal-doc-automation') . '</h5>';
        echo '<ol>';
        echo '<li>' . __('Go to the <a href="https://console.developers.google.com/" target="_blank">Google Cloud Console</a>', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Create a new project or select an existing one', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Enable the Google Drive API', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Create credentials (Service Account)', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Download the JSON credentials file', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Upload the file using the button below', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Share your Google Drive folder with the service account email', 'legal-doc-automation') . '</li>';
        echo '</ol>';
        
        echo '<p><strong>' . __('File upload:', 'legal-doc-automation') . '</strong></p>';
        echo '<input type="file" id="gdrive_credentials_upload" accept=".json" />';
        echo '<button type="button" id="upload_credentials" class="button">' . __('Upload Credentials', 'legal-doc-automation') . '</button>';
        echo '<div id="upload_result"></div>';
        
        echo '</div>';
    }

    public function googleDriveClientIdCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_client_id']) ? $options['google_drive_client_id'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<h4>' . __('Google Drive OAuth Setup', 'legal-doc-automation') . '</h4>';
        echo '<p class="description">' . __('Set up OAuth to upload files to your actual Google Drive account.', 'legal-doc-automation') . '</p>';
        
        echo '<p><label for="google_drive_client_id"><strong>' . __('Client ID:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_client_id]" id="google_drive_client_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your Google Drive OAuth Client ID from Google Cloud Console.', 'legal-doc-automation') . '</p>';
        echo '</div>';
    }
    
    public function googleDriveClientSecretCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_client_secret']) ? $options['google_drive_client_secret'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<p><label for="google_drive_client_secret"><strong>' . __('Client Secret:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="password" name="lda_settings[google_drive_client_secret]" id="google_drive_client_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your Google Drive OAuth Client Secret from Google Cloud Console.', 'legal-doc-automation') . '</p>';
        echo '</div>';
    }

    public function googleDriveAccessTokenCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_access_token']) ? $options['google_drive_access_token'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<h4>' . __('OAuth Access Token Setup', 'legal-doc-automation') . '</h4>';
        echo '<p class="description">' . __('If you have a Google Drive application that uses OAuth authentication, enter the access token here.', 'legal-doc-automation') . '</p>';
        
        echo '<p><label for="google_drive_access_token"><strong>' . __('Access Token:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_access_token]" id="google_drive_access_token" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('OAuth access token from your Google Drive application. Get this from your Google Drive app that created the folder.', 'legal-doc-automation') . '</p>';
        
        echo '</div>';
    }

    public function googleDriveFolderIdCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_folder_id']) ? $options['google_drive_folder_id'] : '';
        
        echo '<p><label for="google_drive_folder_id"><strong>' . __('Folder ID:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_folder_id]" id="google_drive_folder_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The ID of the Google Drive folder where documents will be stored. You can find this in the folder URL.', 'legal-doc-automation') . '</p>';
    }
    
    public function useYourDrivePluginCallback() {
        echo '<div id="use_your_drive_section" class="gdrive-method-section">';
        echo '<h4>' . __('Use-your-Drive Plugin Setup', 'legal-doc-automation') . '</h4>';
        
        if (is_plugin_active('use-your-drive/use-your-drive.php')) {
            echo '<div class="notice notice-success inline"><p><strong>✓</strong> ' . __('Use-your-Drive plugin is active and ready to use.', 'legal-doc-automation') . '</p></div>';
            echo '<p class="description">' . __('The plugin will automatically use the Use-your-Drive plugin for Google Drive integration. Make sure you have configured your Google Drive accounts in the Use-your-Drive plugin settings.', 'legal-doc-automation') . '</p>';
        } else {
            echo '<div class="notice notice-error inline"><p><strong>✗</strong> ' . __('Use-your-Drive plugin is not active.', 'legal-doc-automation') . '</p></div>';
            echo '<p class="description">' . __('Please install and activate the <a href="https://wordpress.org/plugins/use-your-drive/" target="_blank">Use-your-Drive plugin</a> to use this integration method.', 'legal-doc-automation') . '</p>';
        }
        
        echo '</div>';
    }
    
    public function googleDriveRootFolderCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_root_folder']) ? $options['gdrive_root_folder'] : 'Legal Documents';
        echo '<input type="text" name="lda_settings[gdrive_root_folder]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Root folder name in Google Drive for all legal documents', 'legal-doc-automation') . '</p>';
    }
    
    public function gdrivefolderNamingCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_folder_naming']) ? $options['gdrive_folder_naming'] : 'business_name';
        
        $strategies = array(
            'business_name' => __('Business Name + User ID', 'legal-doc-automation'),
            'user_name' => __('User Name + User ID', 'legal-doc-automation'),
            'user_id' => __('User ID Only', 'legal-doc-automation')
        );
        
        echo '<select name="lda_settings[gdrive_folder_naming]">';
        foreach ($strategies as $key => $label) {
            $selected = ($value == $key) ? 'selected' : '';
            echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';
    }
    
    public function gdriveFilenameFormatCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_filename_format']) ? $options['gdrive_filename_format'] : 'form_business_date';
        
        $formats = array(
            'form_business_date' => __('Form_Business_Date', 'legal-doc-automation'),
            'business_form_date' => __('Business_Form_Date', 'legal-doc-automation'),
            'entry_id_date' => __('Entry_ID_Date', 'legal-doc-automation')
        );
        
        echo '<select name="lda_settings[gdrive_filename_format]">';
        foreach ($formats as $key => $label) {
            $selected = ($value == $key) ? 'selected' : '';
            echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';
    }
    
    public function debugModeCallback() {
        $options = get_option('lda_settings');
        $checked = isset($options['debug_mode']) && $options['debug_mode'] ? 'checked' : '';
        echo '<input type="checkbox" name="lda_settings[debug_mode]" value="1" ' . $checked . '>';
        echo '<p class="description">' . __('Enable detailed logging for troubleshooting', 'legal-doc-automation') . '</p>';
    }
    
    public function maxProcessingTimeCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['max_processing_time']) ? $options['max_processing_time'] : 300;
        echo '<input type="number" name="lda_settings[max_processing_time]" value="' . esc_attr($value) . '" min="30" max="3600" />';
        echo '<p class="description">' . __('Maximum time in seconds for document processing', 'legal-doc-automation') . '</p>';
    }
    
    public function maxMemoryUsageCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['max_memory_usage']) ? $options['max_memory_usage'] : '512M';
        echo '<input type="text" name="lda_settings[max_memory_usage]" value="' . esc_attr($value) . '" class="small-text" />';
        echo '<p class="description">' . __('Maximum memory usage (e.g., 512M, 1G)', 'legal-doc-automation') . '</p>';
    }
    
    /**
     * Handle template upload
     */
    private function handleTemplateUpload() {
        if (!isset($_POST['lda_upload_nonce']) || !wp_verify_nonce($_POST['lda_upload_nonce'], 'lda_upload_template')) {
            return; // Nonce not set or invalid.
        }

        if (!current_user_can('manage_options')) {
            LDA_Logger::error('User without manage_options capability tried to upload a template.');
            return; // Permission denied.
        }

        if (!isset($_FILES['template_file']) || !is_uploaded_file($_FILES['template_file']['tmp_name'])) {
            // This handles the case where the form is submitted without a file.
            return;
        }

        $file = $_FILES['template_file'];

        // Check for PHP upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->showUploadError($file['error']);
            LDA_Logger::error('Template upload failed with PHP error code: ' . $file['error']);
            return;
        }

        // Validate file type
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'docx') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Error: Only .docx files are allowed.', 'legal-doc-automation') . '</p></div>';
            LDA_Logger::error('Template upload failed: Invalid file type uploaded (' . $file_ext . ').');
            return;
        }

        // Define the template folder path directly.
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';

        // Check if template directory exists and is writable
        if (!is_dir($template_folder)) {
            // Try to create it
            if (!wp_mkdir_p($template_folder)) {
                $error_msg = 'Template directory does not exist and could not be created: ' . esc_html($template_folder);
                echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . $error_msg . '</p></div>';
                LDA_Logger::error($error_msg);
                return;
            }
        }

        if (!is_writable($template_folder)) {
            $error_msg = 'Template directory is not writable: ' . esc_html($template_folder);
            echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . $error_msg . '</p></div>';
            LDA_Logger::error($error_msg);
            return;
        }

        $filename = sanitize_file_name($file['name']);
        $destination = $template_folder . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Template "%s" uploaded successfully.', 'legal-doc-automation'), esc_html($filename)) . '</p></div>';
            LDA_Logger::log('Template uploaded successfully: ' . $filename);
        } else {
            $error_msg = 'Failed to move uploaded file to destination.';
            echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . esc_html__($error_msg, 'legal-doc-automation') . '</p></div>';
            LDA_Logger::error($error_msg . ' Destination: ' . $destination);
        }
    }

    // Helper function to show human-readable upload errors
    private function showUploadError($error_code) {
        $error_messages = array(
            UPLOAD_ERR_INI_SIZE   => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'legal-doc-automation'),
            UPLOAD_ERR_FORM_SIZE  => __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'legal-doc-automation'),
            UPLOAD_ERR_PARTIAL    => __('The uploaded file was only partially uploaded.', 'legal-doc-automation'),
            UPLOAD_ERR_NO_FILE    => __('No file was uploaded.', 'legal-doc-automation'),
            UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'legal-doc-automation'),
            UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'legal-doc-automation'),
            UPLOAD_ERR_EXTENSION  => __('A PHP extension stopped the file upload.', 'legal-doc-automation'),
        );
        $message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : __('Unknown upload error.', 'legal-doc-automation');
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
    
    /**
     * Display templates list
     */
    private function displayTemplatesList() {
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        if (!is_dir($template_folder)) {
            echo '<p>' . __('Template folder not configured or not accessible.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        $templates = glob($template_folder . '*.docx');
        
        if (empty($templates)) {
            echo '<p>' . __('No template files found', 'legal-doc-automation') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('Template File', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Size', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Modified', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Webmerge Tags', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Actions', 'legal-doc-automation') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($templates as $template) {
                $filename = basename($template);
                $size = size_format(filesize($template));
                $modified = date('Y-m-d H:i:s', filemtime($template));
                
                // Quick analysis
                $tag_count = $this->analyzeTemplateQuick($template);
                
                echo '<tr>';
                echo '<td><strong>' . esc_html($filename) . '</strong></td>';
                echo '<td>' . $size . '</td>';
                echo '<td>' . $modified . '</td>';
                echo '<td>' . $tag_count . '</td>';
                echo '<td>';
                echo '<button type="button" class="button validate-template" data-template="' . esc_attr($filename) . '">' . __('Validate', 'legal-doc-automation') . '</button> ';
                echo '<button type="button" class="button test-template" data-template="' . esc_attr($filename) . '">' . __('Test', 'legal-doc-automation') . '</button> ';
                echo '<button type="button" class="button button-secondary delete-template" data-template="' . esc_attr($filename) . '">' . __('Delete', 'legal-doc-automation') . '</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
    }
    
    /**
     * Quick template analysis
     */
    private function analyzeTemplateQuick($template_path) {
        try {
            // Simple count of merge tags - this is just for display
            $content = '';
            $zip = new ZipArchive();
            if ($zip->open($template_path) === true) {
                $content = $zip->getFromName('word/document.xml');
                $zip->close();
            }
            
            $count = substr_count($content, '{$');
            return $count > 0 ? $count . ' tags' : 'No tags found';
            
        } catch (Exception $e) {
            return 'Analysis failed';
        }
    }
    
    /**
     * Populate template options for select dropdowns
     */
    private function populateTemplateOptions() {
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        if (is_dir($template_folder)) {
            $templates = glob($template_folder . '*.docx');
            foreach ($templates as $template) {
                $filename = basename($template);
                echo '<option value="' . esc_attr($filename) . '">' . esc_html($filename) . '</option>';
            }
        }
    }
    
    /**
     * Display Google Drive status
     */
    private function displayGoogleDriveStatus() {
        $settings = get_option('lda_settings');
        
        if (!isset($settings['google_drive_enabled']) || !$settings['google_drive_enabled']) {
            echo '<p class="notice notice-warning inline">' . __('Google Drive integration is disabled.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        try {
            // Use appropriate Google Drive class based on selected method
            $gdrive_method = isset($settings['google_drive_method']) ? $settings['google_drive_method'] : 'oauth';
            
            if ($gdrive_method === 'use_your_drive' && is_plugin_active('use-your-drive/use-your-drive.php')) {
                $gdrive = new LDA_GoogleDrive($settings);
                $status = $gdrive->testConnection();
            } elseif ($gdrive_method === 'oauth') {
                $gdrive = new LDA_RealGoogleDrive($settings);
                $status = $gdrive->testConnection();
            } else {
                $gdrive = new LDA_SimpleGoogleDrive($settings);
                    $status = $gdrive->testConnection();
            }
            
            if ($status && $status['success']) {
                echo '<div class="notice notice-success inline">';
                echo '<p><strong>' . __('Google Drive Connection: OK', 'legal-doc-automation') . '</strong></p>';
                echo '<ul>';
                echo '<li>' . sprintf(__('Method: %s', 'legal-doc-automation'), ucfirst(str_replace('_', ' ', $gdrive_method))) . '</li>';
                if (isset($status['user_email'])) {
                    echo '<li>' . sprintf(__('Connected as: %s', 'legal-doc-automation'), $status['user_email']) . '</li>';
                }
                echo '<li>' . sprintf(__('Last checked: %s', 'legal-doc-automation'), current_time('Y-m-d H:i:s')) . '</li>';
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error inline">';
                echo '<p><strong>' . __('Google Drive Connection: Failed', 'legal-doc-automation') . '</strong></p>';
                $error_msg = isset($status['error']) ? $status['error'] : 'Unknown error';
                echo '<p>' . esc_html($error_msg) . '</p>';
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="notice notice-error inline">';
            echo '<p><strong>' . __('Google Drive Connection: Error', 'legal-doc-automation') . '</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Display recent logs
     */
    private function displayRecentLogs() {
        echo $this->getRecentLogsHTML();
    }

    /**
     * Gets HTML for recent logs.
     * @return string The logs formatted as HTML.
     */
    private function getRecentLogsHTML() {
        if (!class_exists('LDA_Logger')) {
            return '<p>' . __('Logger class not available.', 'legal-doc-automation') . '</p>';
        }
        
        $logs = LDA_Logger::getRecentLogs();
        
        if (empty($logs) || (isset($logs[0]) && strpos($logs[0], 'Log file not found') !== false)) {
            return '<p>' . __('No log entries found.', 'legal-doc-automation') . '</p>';
        }
        
        $html = '<div class="log-entries">';
        foreach (array_slice($logs, 0, 100) as $log) { // Show latest 100 entries
            preg_match('/\[(.*?)\]\s\[(.*?)\]\s(.*)/', $log, $matches);

            if (count($matches) === 4) {
                $timestamp = esc_html($matches[1]);
                $level     = esc_html($matches[2]);
                $message   = esc_html($matches[3]);

                $level_class = 'log-' . strtolower($level);
                $html .= '<div class="log-entry ' . $level_class . '">';
                $html .= '<span class="log-timestamp">' . $timestamp . '</span>';
                $html .= '<span class="log-level">' . $level . '</span>';
                $html .= '<span class="log-message">' . $message . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        return $html;
    }
    
    /**
     * Display log statistics
     */
    private function displayLogStats() {
        if (!class_exists('LDA_Logger')) {
            return;
        }
        
        $stats = LDA_Logger::getLogStats(); // Get all log stats
        
        echo '<div class="log-stats">';
        echo '<div class="stat-item">';
        echo '<h4>' . __('Total Entries', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['total_entries'] . '</span>';
        echo '</div>';
        
        echo '<div class="stat-item error">';
        echo '<h4>' . __('Errors', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['error_count'] . '</span>';
        echo '</div>';
        
        echo '<div class="stat-item warning">';
        echo '<h4>' . __('Warnings', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['warning_count'] . '</span>';
        echo '</div>';
        
        if ($stats['latest_error']) {
            echo '<div class="latest-error">';
            echo '<h4>' . __('Latest Error', 'legal-doc-automation') . '</h4>';
            echo '<p><strong>' . $stats['latest_error']['timestamp'] . ':</strong> ' . esc_html($stats['latest_error']['message']) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * Display system status
     */
    private function displaySystemStatus() {
        $requirements = array(
            'PHP Version' => array(
                'required' => LDA_MIN_PHP_VERSION,
                'current' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, LDA_MIN_PHP_VERSION, '>=')
            ),
            'WordPress Version' => array(
                'required' => LDA_MIN_WP_VERSION,
                'current' => get_bloginfo('version'),
                'status' => version_compare(get_bloginfo('version'), LDA_MIN_WP_VERSION, '>=')
            ),
            'DOCX Processing' => array(
                'required' => 'Available',
                'current' => LDA_SimpleDOCX::isAvailable() ? 'Available' : 'Not Found',
                'status' => LDA_SimpleDOCX::isAvailable()
            ),
            'ZIP Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('zip') ? 'Available' : 'Not Found',
                'status' => extension_loaded('zip')
            ),
            'XML Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('xml') ? 'Available' : 'Not Found',
                'status' => extension_loaded('xml')
            ),
            'mbstring Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('mbstring') ? 'Available' : 'Not Found',
                'status' => extension_loaded('mbstring')
            )
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Component', 'legal-doc-automation') . '</th><th>' . __('Status', 'legal-doc-automation') . '</th><th>' . __('Details', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($requirements as $name => $req) {
            $status_text = $req['status'] ? '<span class="status-ok">✓ OK</span>' : '<span class="status-error">✗ Failed</span>';
            $details = $req['current'];
            if (isset($req['required'])) {
                $details .= ' (Required: ' . $req['required'] . ')';
            }
            
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong></td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . $details . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display plugin status
     */
    private function displayPluginStatus() {
        if (!function_exists('is_plugin_active') || !function_exists('get_plugin_data')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $required_plugins = array(
            'gravityforms/gravityforms.php' => 'Gravity Forms',
            'memberpress/memberpress.php' => 'MemberPress',
            'use-your-drive/use-your-drive.php' => 'WP Cloud Plugins - Use-your-Drive'
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Plugin', 'legal-doc-automation') . '</th><th>' . esc_html__('Status', 'legal-doc-automation') . '</th><th>' . esc_html__('Version', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($required_plugins as $plugin_path => $plugin_name) {
            $is_active = is_plugin_active($plugin_path);
            
            if ($is_active) {
                $status_text = '<span style="color: #4CAF50; font-weight: bold;">✓ ' . esc_html__('Active', 'legal-doc-automation') . '</span>';
            } else {
                $status_text = '<span style="color: #F44336; font-weight: bold;">✗ ' . esc_html__('Inactive', 'legal-doc-automation') . '</span>';
            }

            $version = esc_html__('N/A', 'legal-doc-automation');
            if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_path)) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
                $version = !empty($plugin_data['Version']) ? esc_html($plugin_data['Version']) : esc_html__('Unknown', 'legal-doc-automation');
            }
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($plugin_name) . '</strong><br/><small><code>' . esc_html($plugin_path) . '</code></small></td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . $version . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<p class="description" style="margin-top: 1em;">' . esc_html__('For full functionality, all plugins listed above must be installed and active. If a plugin is marked as "Inactive", please go to the main Plugins page and activate it. If you believe a plugin is active but it is showing as Inactive here, the file path listed under the plugin name may be incorrect. Please contact support for assistance.', 'legal-doc-automation') . '</p>';
    }
    
    /**
     * Display directory status
     */
    private function displayDirectoryStatus() {
        $upload_dir = wp_upload_dir();
        $directories = array(
            'Templates' => $upload_dir['basedir'] . '/lda-templates/',
            'Output' => $upload_dir['basedir'] . '/lda-output/',
            'Logs' => $upload_dir['basedir'] . '/lda-logs/',
            'Google Drive Fallback' => $upload_dir['basedir'] . '/lda-gdrive-fallback/'
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Directory', 'legal-doc-automation') . '</th><th>' . __('Status', 'legal-doc-automation') . '</th><th>' . __('Path', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($directories as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            
            if ($exists && $writable) {
                $status = '<span class="status-ok">✓ OK</span>';
            } elseif ($exists) {
                $status = '<span class="status-warning">⚠ Not Writable</span>';
            } else {
                $status = '<span class="status-error">✗ Not Found</span>';
            }
            
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong></td>';
            echo '<td>' . $status . '</td>';
            echo '<td><code>' . esc_html($path) . '</code></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display processing statistics
     */
    private function displayProcessingStats() {
        // This would require implementing statistics collection in the actual processing
        echo '<div class="processing-stats">';
        echo '<div class="stat-item">';
        echo '<h4>' . __('Documents Generated', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">--</span>';
        echo '<p class="description">' . __('Statistics collection coming soon', 'legal-doc-automation') . '</p>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Add form-specific settings to Gravity Forms
     */
    public function addFormSettings($fields, $form) {
        // Get current settings
        $enabled = gform_get_meta($form['id'], 'lda_enabled');
        $template_file = gform_get_meta($form['id'], 'lda_template_file');
        
        // Debug logging
        LDA_Logger::debug("Loading form settings - Enabled: " . ($enabled ? '1' : '0') . ", Template: " . $template_file);
        
        $fields[] = array(
            'title'  => __('Document Automation', 'legal-doc-automation'),
            'fields' => array(
                array(
                    'label'   => __('Enable Document Generation', 'legal-doc-automation'),
                    'type'    => 'checkbox',
                    'name'    => 'lda_enabled',
                    'tooltip' => __('Enable document generation for submissions to this form.', 'legal-doc-automation'),
                    'choices' => array(
                        array(
                            'label' => __('Enabled', 'legal-doc-automation'),
                            'name'  => 'lda_enabled',
                            'isSelected' => ($enabled === '1' || $enabled === 1 || $enabled === true),
                        ),
                    ),
                ),
                array(
                    'label'   => __('Template File', 'legal-doc-automation'),
                    'type'    => 'select',
                    'name'    => 'lda_template_file',
                    'tooltip' => __('Select the .docx template to be used for document generation for this form.', 'legal-doc-automation'),
                    'choices' => $this->get_template_choices($template_file),
                ),
            ),
        );

        return $fields;
    }
    
    /**
     * Gets a list of available templates for use in a select field.
     *
     * @param string $current_template The currently selected template file
     * @return array
     */
    private function get_template_choices($current_template = '') {
        $choices = array(
            array(
                'label' => __('Select a template', 'legal-doc-automation'),
                'value' => '',
                'isSelected' => empty($current_template)
            )
        );

        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        if (is_dir($template_folder)) {
            $templates = glob($template_folder . '*.docx');
            foreach ($templates as $template) {
                $filename = basename($template);
                $choices[] = array(
                    'label' => $filename,
                    'value' => $filename,
                    'isSelected' => ($filename === $current_template)
                );
            }
        }

        return $choices;
    }

    /**
     * Save form-specific settings
     */
    public function saveFormSettings($form) {
        // Save the settings using Gravity Forms meta API
        if (isset($_POST['lda_template_file'])) {
            $template_file = sanitize_text_field($_POST['lda_template_file']);
            gform_update_meta($form['id'], 'lda_template_file', $template_file);
            $form['lda_template_file'] = $template_file;
        }
        
        // Handle checkbox - if not in POST, it means unchecked
        $enabled = isset($_POST['lda_enabled']) ? '1' : '0';
        gform_update_meta($form['id'], 'lda_enabled', $enabled);
        $form['lda_enabled'] = $enabled;
        
        // Debug logging
        LDA_Logger::debug("Saving form settings - Enabled: " . $enabled . ", Template: " . (isset($_POST['lda_template_file']) ? $_POST['lda_template_file'] : 'not set'));
        
        return $form;
    }

    // --- New AJAX Handlers ---

    public function handleAjaxGetTemplates() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        // This is a simplified version for now.
        // A real version would scan the directory and return a list of files.
        wp_send_json_success(array());
    }

    public function handleAjaxTestTemplate() {
        try {
        check_ajax_referer('lda_admin_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }
            
            $template_file = sanitize_text_field($_POST['template_file']);
            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template_file;
            
            if (!file_exists($template_path)) {
                wp_send_json_error('Template file not found');
            }
            
            // Create sample merge data for testing
            $sample_data = $this->generateSampleMergeData();
            
            // Test the merge process
            $output_folder = wp_upload_dir()['basedir'] . '/lda-output/';
            $output_filename = 'test_' . time() . '_' . $template_file;
            $output_path = $output_folder . $output_filename;
            
            // Use our enhanced merge engine
            $settings = get_option('lda_settings', array());
            $merge_engine = new LDA_MergeEngine($settings);
            $result = $merge_engine->mergeDocument($template_path, $output_path, $sample_data);
            
            if ($result['success']) {
                $result['test_file'] = $output_filename;
                $result['sample_data'] = $sample_data;
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate sample merge data for testing
     */
    private function generateSampleMergeData() {
        return array(
            'USR_Name' => 'John Smith',
            'PT2_Name' => 'Jane Doe',
            'USR_Business' => 'Smith & Associates',
            'PT2_Business' => 'Doe Enterprises',
            'USR_ABN' => '12345678901',
            'PT2_ABN' => '98765432109',
            'USR_ABV' => 'S&A',
            'PT2_ABV' => 'DE',
            'USR_Address' => '123 Business Street, Sydney NSW 2000',
            'PT2_Address' => '456 Corporate Avenue, Melbourne VIC 3000',
            'USR_Email' => 'john@smithassociates.com',
            'PT2_Email' => 'jane@doeenterprises.com',
            'USR_Phone' => '02 1234 5678',
            'PT2_Phone' => '03 9876 5432',
            'EffectiveDate' => date('d F Y'),
            'FormTitle' => 'Test Agreement',
            'Concept' => 'This is a test concept for template validation.',
            'UserFirstName' => 'John',
            'UserLastName' => 'Smith',
            'CounterpartyFirstName' => 'Jane',
            'CounterpartyLastName' => 'Doe'
        );
    }

    public function handleAjaxTestProcessing() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        wp_send_json_success('Processing test functionality coming soon!');
    }

    public function handleAjaxTestModifier() {
        try {
        check_ajax_referer('lda_admin_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }
            
            $expression = sanitize_text_field($_POST['expression']);
            $test_value = sanitize_text_field($_POST['test_value']);
            
            if (empty($expression) || empty($test_value)) {
                wp_send_json_error('Both expression and test value are required');
            }
            
            // Parse the modifier expression
            $result = $this->testModifierExpression($expression, $test_value);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error('Modifier test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Test modifier expressions
     */
    private function testModifierExpression($expression, $test_value) {
        $result = array(
            'original_expression' => $expression,
            'test_value' => $test_value,
            'processed_value' => $test_value,
            'modifiers_applied' => array(),
            'success' => true,
            'error' => null
        );
        
        try {
            // Extract modifiers from expression like {$Field|modifier1|modifier2}
            if (preg_match('/\{\$[^|]+\|(.+)\}/', $expression, $matches)) {
                $modifier_part = $matches[1];
                $modifiers = explode('|', $modifier_part);
                
                $processed_value = $test_value;
                
                foreach ($modifiers as $modifier) {
                    $modifier = trim($modifier);
                    $before_value = $processed_value;
                    
                    // Apply different modifier types
                    if (strpos($modifier, 'upper') === 0) {
                        $processed_value = strtoupper($processed_value);
                        $result['modifiers_applied'][] = 'upper: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'lower') === 0) {
                        $processed_value = strtolower($processed_value);
                        $result['modifiers_applied'][] = 'lower: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'ucwords') === 0) {
                        $processed_value = ucwords($processed_value);
                        $result['modifiers_applied'][] = 'ucwords: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'phone_format') === 0) {
                        $format = str_replace('phone_format:', '', $modifier);
                        $format = trim($format, '"');
                        $processed_value = $this->formatPhoneNumber($processed_value, $format);
                        $result['modifiers_applied'][] = 'phone_format: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'date_format') === 0) {
                        $format = str_replace('date_format:', '', $modifier);
                        $format = trim($format, '"');
                        $processed_value = $this->formatDate($processed_value, $format);
                        $result['modifiers_applied'][] = 'date_format: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'replace') === 0) {
                        // Handle replace:old,new format
                        $replace_parts = explode(':', $modifier, 2);
                        if (count($replace_parts) === 2) {
                            $replace_data = explode(',', $replace_parts[1], 2);
                            if (count($replace_data) === 2) {
                                $old = trim($replace_data[0], '"');
                                $new = trim($replace_data[1], '"');
                                $processed_value = str_replace($old, $new, $processed_value);
                                $result['modifiers_applied'][] = 'replace: ' . $before_value . ' → ' . $processed_value;
                            }
                        }
                    } else {
                        $result['modifiers_applied'][] = 'unknown modifier: ' . $modifier;
                    }
                }
                
                $result['processed_value'] = $processed_value;
            } else {
                $result['error'] = 'Invalid modifier expression format';
                $result['success'] = false;
            }
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $result['success'] = false;
        }
        
        return $result;
    }
    
    /**
     * Format phone number
     */
    private function formatPhoneNumber($phone, $format) {
        // Remove all non-digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Apply format like "%2 %3 %3 %3" for 02 1234 5678
        $formatted = $format;
        $digit_index = 1;
        
        for ($i = 0; $i < strlen($digits); $i++) {
            $formatted = str_replace('%' . $digit_index, $digits[$i], $formatted);
            $digit_index++;
        }
        
        return $formatted;
    }
    
    /**
     * Format date
     */
    private function formatDate($date, $format) {
        try {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                return $date; // Return original if can't parse
            }
            return date($format, $timestamp);
        } catch (Exception $e) {
            return $date;
        }
    }
    
    /**
     * Show Merge Tags Management tab (Webmerge-style interface)
     */
    private function showMergeTagsTab() {
        ?>
        <div class="lda-merge-tags-section">
            <h2><?php _e('Merge Tags Management', 'legal-doc-automation'); ?></h2>
            <p><?php _e('Manage your merge tags and field mappings. This replaces the need for Webmerge/Formstack by providing a comprehensive merge tag system.', 'legal-doc-automation'); ?></p>
            
            <!-- Available Merge Tags Library -->
            <div class="lda-card">
                <h3><?php _e('Available Merge Tags Library', 'legal-doc-automation'); ?></h3>
                <p><?php _e('All merge tags available for use in your templates. These are automatically detected from your forms and can be used with the format {$TagName}.', 'legal-doc-automation'); ?></p>
                
                <div class="merge-tags-library">
                    <div class="merge-tags-categories">
                        <button type="button" class="button category-btn active" data-category="all"><?php _e('All Tags', 'legal-doc-automation'); ?></button>
                        <button type="button" class="button category-btn" data-category="form"><?php _e('Form Fields', 'legal-doc-automation'); ?></button>
                        <button type="button" class="button category-btn" data-category="user"><?php _e('User Info', 'legal-doc-automation'); ?></button>
                        <button type="button" class="button category-btn" data-category="legal"><?php _e('Legal Fields', 'legal-doc-automation'); ?></button>
                        <button type="button" class="button category-btn" data-category="custom"><?php _e('Custom Tags', 'legal-doc-automation'); ?></button>
                    </div>
                    
                    <div id="merge-tags-list" class="merge-tags-list">
                        <?php $this->displayMergeTagsLibrary(); ?>
                    </div>
                </div>
            </div>
            
            <!-- Custom Merge Tags -->
            <div class="lda-card">
                <h3><?php _e('Custom Merge Tags', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Create custom merge tags with specific values that will be used across all documents.', 'legal-doc-automation'); ?></p>
                
                <form id="custom-merge-tag-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Tag Name', 'legal-doc-automation'); ?></th>
                            <td>
                                <input type="text" id="custom-tag-name" class="regular-text" placeholder="CUSTOM_TAG" />
                                <p class="description"><?php _e('Use uppercase letters and underscores. Will be used as {$CUSTOM_TAG}', 'legal-doc-automation'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Description', 'legal-doc-automation'); ?></th>
                            <td>
                                <input type="text" id="custom-tag-description" class="regular-text" placeholder="Description of what this tag represents" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Default Value', 'legal-doc-automation'); ?></th>
                            <td>
                                <input type="text" id="custom-tag-value" class="regular-text" placeholder="Default value for this tag" />
                                <p class="description"><?php _e('This value will be used if no form field matches this tag', 'legal-doc-automation'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <button type="button" id="add-custom-tag" class="button button-primary"><?php _e('Add Custom Tag', 'legal-doc-automation'); ?></button>
                </form>
                
                <div id="custom-tags-list">
                    <?php $this->displayCustomMergeTags(); ?>
                </div>
            </div>
            
            <!-- Field Mapping -->
            <div class="lda-card">
                <h3><?php _e('Field Mapping', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Map specific form fields to merge tags. This allows you to use consistent merge tag names regardless of how form fields are named.', 'legal-doc-automation'); ?></p>
                
                <div class="field-mapping-interface">
                    <div class="mapping-row">
                        <select id="source-form" class="regular-text">
                            <option value=""><?php _e('Select a form...', 'legal-doc-automation'); ?></option>
                            <?php $this->populateFormOptions(); ?>
                        </select>
                        <select id="source-field" class="regular-text" disabled>
                            <option value=""><?php _e('Select a field...', 'legal-doc-automation'); ?></option>
                        </select>
                        <span class="mapping-arrow">→</span>
                        <input type="text" id="target-merge-tag" class="regular-text" placeholder="TARGET_MERGE_TAG" />
                        <button type="button" id="add-field-mapping" class="button"><?php _e('Add Mapping', 'legal-doc-automation'); ?></button>
                    </div>
                </div>
                
                <div id="field-mappings-list">
                    <?php $this->displayFieldMappings(); ?>
                </div>
            </div>
            
            <!-- Template Validation -->
            <div class="lda-card">
                <h3><?php _e('Template Validation', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Validate your templates to ensure all merge tags have corresponding data sources.', 'legal-doc-automation'); ?></p>
                
                <div class="template-validation-interface">
                    <select id="validation-template-select">
                        <option value=""><?php _e('Select a template to validate...', 'legal-doc-automation'); ?></option>
                        <?php $this->populateTemplateOptions(); ?>
                    </select>
                    <button type="button" id="validate-template-comprehensive" class="button button-primary"><?php _e('Validate Template', 'legal-doc-automation'); ?></button>
                </div>
                
                <div id="validation-results" class="validation-results"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display merge tags library
     */
    private function displayMergeTagsLibrary() {
        $available_tags = $this->getAllAvailableMergeTags();
        
        echo '<div class="merge-tags-grid">';
        
        // Group tags by category
        $categories = array(
            'form' => array(),
            'user' => array(),
            'legal' => array(),
            'custom' => array()
        );
        
        foreach ($available_tags as $tag => $description) {
            if (in_array($tag, array('FormTitle', 'FormID', 'EntryID', 'DateCreated'))) {
                $categories['form'][$tag] = $description;
            } elseif (in_array($tag, array('UserFirstName', 'UserLastName', 'UserEmail'))) {
                $categories['user'][$tag] = $description;
            } elseif (strpos($tag, 'USR_') === 0 || strpos($tag, 'PT2_') === 0) {
                $categories['legal'][$tag] = $description;
            } else {
                $categories['custom'][$tag] = $description;
            }
        }
        
        foreach ($categories as $category => $tags) {
            if (!empty($tags)) {
                echo '<div class="merge-tag-category" data-category="' . $category . '">';
                echo '<h4>' . ucfirst($category) . ' Tags</h4>';
                foreach ($tags as $tag => $description) {
                    echo '<div class="merge-tag-item" data-tag="' . esc_attr($tag) . '">';
                    echo '<code>{$' . esc_html($tag) . '}</code>';
                    echo '<span class="tag-description">' . esc_html($description) . '</span>';
                    echo '<button type="button" class="button copy-tag" data-tag="{$' . esc_attr($tag) . '}">' . __('Copy', 'legal-doc-automation') . '</button>';
                    echo '</div>';
                }
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Display custom merge tags
     */
    private function displayCustomMergeTags() {
        $custom_tags = get_option('lda_custom_merge_tags', array());
        
        if (empty($custom_tags)) {
            echo '<p>' . __('No custom merge tags created yet.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Tag Name', 'legal-doc-automation') . '</th><th>' . __('Description', 'legal-doc-automation') . '</th><th>' . __('Default Value', 'legal-doc-automation') . '</th><th>' . __('Actions', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($custom_tags as $tag => $data) {
            echo '<tr>';
            echo '<td><code>{$' . esc_html($tag) . '}</code></td>';
            echo '<td>' . esc_html($data['description']) . '</td>';
            echo '<td>' . esc_html($data['value']) . '</td>';
            echo '<td>';
            echo '<button type="button" class="button edit-custom-tag" data-tag="' . esc_attr($tag) . '">' . __('Edit', 'legal-doc-automation') . '</button> ';
            echo '<button type="button" class="button delete-custom-tag" data-tag="' . esc_attr($tag) . '">' . __('Delete', 'legal-doc-automation') . '</button>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display field mappings
     */
    private function displayFieldMappings() {
        $mappings = get_option('lda_field_mappings', array());
        
        if (empty($mappings)) {
            echo '<p>' . __('No field mappings created yet.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Form', 'legal-doc-automation') . '</th><th>' . __('Source Field', 'legal-doc-automation') . '</th><th>' . __('Target Merge Tag', 'legal-doc-automation') . '</th><th>' . __('Actions', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($mappings as $mapping) {
            echo '<tr>';
            echo '<td>' . esc_html($mapping['form_title']) . '</td>';
            echo '<td>' . esc_html($mapping['field_label']) . '</td>';
            echo '<td><code>{$' . esc_html($mapping['merge_tag']) . '}</code></td>';
            echo '<td>';
            echo '<button type="button" class="button delete-mapping" data-mapping-id="' . esc_attr($mapping['id']) . '">' . __('Delete', 'legal-doc-automation') . '</button>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Populate form options for field mapping
     */
    private function populateFormOptions() {
        if (class_exists('GFAPI')) {
            $forms = GFAPI::get_forms();
            foreach ($forms as $form) {
                echo '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
            }
        }
    }
    
    /**
     * Get all available merge tags (enhanced version)
     */
    private function getAllAvailableMergeTags() {
        $available_tags = array();
        
        // Get all Gravity Forms
        if (class_exists('GFAPI')) {
            $forms = GFAPI::get_forms();
            
            foreach ($forms as $form) {
                // Add form-level merge tags
                $available_tags['FormTitle'] = 'Form Title';
                $available_tags['FormID'] = 'Form ID';
                $available_tags['EntryID'] = 'Entry ID';
                $available_tags['DateCreated'] = 'Date Created';
                $available_tags['UserFirstName'] = 'User First Name';
                $available_tags['UserLastName'] = 'User Last Name';
                $available_tags['UserEmail'] = 'User Email';
                
                // Add field-level merge tags
                foreach ($form['fields'] as $field) {
                    if (!empty($field->label)) {
                        $available_tags[$field->label] = $field->label;
                        $available_tags[strtoupper($field->label)] = strtoupper($field->label);
                        $available_tags[str_replace(' ', '_', $field->label)] = str_replace(' ', '_', $field->label);
                        $available_tags[str_replace(' ', '_', strtoupper($field->label))] = str_replace(' ', '_', strtoupper($field->label));
                    }
                    
                    if (!empty($field->adminLabel)) {
                        $available_tags[$field->adminLabel] = $field->adminLabel;
                        $available_tags[strtoupper($field->adminLabel)] = strtoupper($field->adminLabel);
                    }
                    
                    // Add field ID variations
                    $available_tags['field_' . $field->id] = 'Field ' . $field->id;
                    $available_tags['FIELD_' . $field->id] = 'FIELD ' . $field->id;
                }
            }
        }
        
        // Add custom merge tags from settings
        $custom_tags = get_option('lda_custom_merge_tags', array());
        foreach ($custom_tags as $tag => $data) {
            $available_tags[$tag] = $data['description'];
        }
        
        // Add standard legal document merge tags
        $standard_tags = array(
            'USR_Name' => 'User Name',
            'PT2_Name' => 'Counterparty Name',
            'USR_Business' => 'User Business Name',
            'PT2_Business' => 'Counterparty Business Name',
            'USR_ABN' => 'User ABN',
            'PT2_ABN' => 'Counterparty ABN',
            'USR_ABV' => 'User Business Abbreviation',
            'PT2_ABV' => 'Counterparty Business Abbreviation',
            'USR_Address' => 'User Address',
            'PT2_Address' => 'Counterparty Address',
            'USR_Email' => 'User Email',
            'PT2_Email' => 'Counterparty Email',
            'USR_Phone' => 'User Phone',
            'PT2_Phone' => 'Counterparty Phone',
            'EffectiveDate' => 'Effective Date',
            'Concept' => 'Concept/Description'
        );
        
        foreach ($standard_tags as $tag => $description) {
            $available_tags[$tag] = $description;
        }
        
        return $available_tags;
    }

    public function handleAjaxTestEmail() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to test emails.', 'legal-doc-automation'));
        }
        
        $test_email = sanitize_email($_POST['test_email']);
        if (!is_email($test_email)) {
            wp_send_json_error(__('Invalid email address provided.', 'legal-doc-automation'));
        }
        
        try {
            $settings = get_option('lda_settings', array());
            $email_handler = new LDA_EmailHandler($settings);
            
            // Create a test document
            $test_content = "This is a test document generated by Legal Document Automation plugin.\n\n";
            $test_content .= "Test Date: " . date('Y-m-d H:i:s') . "\n";
            $test_content .= "Test Email: " . $test_email . "\n";
            $test_content .= "Site: " . get_bloginfo('name') . "\n\n";
            $test_content .= "If you receive this email, the email configuration is working correctly!";
            
            // Create a temporary test file
            $upload_dir = wp_upload_dir();
            $test_file = $upload_dir['basedir'] . '/lda-output/test-email-' . time() . '.txt';
            file_put_contents($test_file, $test_content);
            
            // Send test email using custom subject from settings
            $subject = !empty($settings['email_subject']) ? $settings['email_subject'] : 'Test Email - Legal Document Automation';
            $message = !empty($settings['email_message']) ? $settings['email_message'] : 'This is a test email from the Legal Document Automation plugin. Please find the test document attached.';
            
            $result = $email_handler->send_document_email($test_email, $subject, $message, $test_file);
            
            // Clean up test file
            if (file_exists($test_file)) {
                unlink($test_file);
            }
            
            if ($result['success']) {
                wp_send_json_success(__('Test email sent successfully to: ', 'legal-doc-automation') . $test_email);
            } else {
                wp_send_json_error(__('Failed to send test email: ', 'legal-doc-automation') . $result['error_message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error sending test email: ', 'legal-doc-automation') . $e->getMessage());
        }
    }

    /**
     * AJAX handler for debugging template merge tags
     */
    public function handleAjaxDebugTemplate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to debug templates.', 'legal-doc-automation'));
        }

        $template_filename = sanitize_text_field($_POST['template_file']);
        if (empty($template_filename)) {
            wp_send_json_error(__('No template file specified.', 'legal-doc-automation'));
        }

        $template_path = wp_upload_dir()['basedir'] . '/lda-templates/' . $template_filename;
        
        if (!file_exists($template_path)) {
            wp_send_json_error(__('Template file not found: ', 'legal-doc-automation') . $template_filename);
        }

        try {
            $merge_tags = LDA_DocumentProcessor::getTemplateMergeTags($template_path);
            
            $result = array(
                'template_file' => $template_filename,
                'merge_tags' => $merge_tags,
                'merge_tags_count' => count($merge_tags),
                'message' => 'Template debug information retrieved successfully.'
            );
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error reading template: ', 'legal-doc-automation') . $e->getMessage());
        }
    }

    public function handleAjaxTestGoogleDrive() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        wp_send_json_success('Google Drive test functionality coming soon!');
    }

    public function handleAjaxUploadGDriveCredentials() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        try {
            LDA_Logger::log("=== CREDENTIALS UPLOAD STARTED ===");
            LDA_Logger::log("FILES array: " . print_r($_FILES, true));
            
            if (!isset($_FILES['credentials_file']) || $_FILES['credentials_file']['error'] !== UPLOAD_ERR_OK) {
                $error_msg = 'No file uploaded or upload error occurred.';
                if (isset($_FILES['credentials_file'])) {
                    $error_msg .= ' Upload error code: ' . $_FILES['credentials_file']['error'];
                }
                LDA_Logger::error($error_msg);
                wp_send_json_error($error_msg);
                return;
            }
            
            $file = $_FILES['credentials_file'];
            
            // Validate file type
            if ($file['type'] !== 'application/json') {
                wp_send_json_error('Please upload a valid JSON file.');
                return;
            }
            
            // Validate file size (max 1MB)
            if ($file['size'] > 1024 * 1024) {
                wp_send_json_error('File size too large. Maximum 1MB allowed.');
                return;
            }
            
            // Read and validate JSON content
            $json_content = file_get_contents($file['tmp_name']);
            $credentials = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON file: ' . json_last_error_msg());
                return;
            }
            
            // Validate required fields
            $required_fields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id'];
            foreach ($required_fields as $field) {
                if (!isset($credentials[$field])) {
                    wp_send_json_error("Missing required field: {$field}");
                    return;
                }
            }
            
            // Ensure it's a service account
            if ($credentials['type'] !== 'service_account') {
                wp_send_json_error('Only service account credentials are supported.');
                return;
            }
            
            // Save to uploads directory
            $upload_dir = wp_upload_dir();
            $credentials_path = $upload_dir['basedir'] . '/lda-google-credentials.json';
            
            if (!wp_mkdir_p($upload_dir['basedir'])) {
                wp_send_json_error('Failed to create uploads directory.');
                return;
            }
            
            if (file_put_contents($credentials_path, $json_content) === false) {
                wp_send_json_error('Failed to save credentials file.');
                return;
            }
            
            // Set proper permissions
            chmod($credentials_path, 0600);
            
            LDA_Logger::log("Google Drive credentials uploaded successfully. Service account: " . $credentials['client_email']);
            
            wp_send_json_success('Google Drive credentials uploaded successfully! Service account: ' . $credentials['client_email']);
            
        } catch (Exception $e) {
            LDA_Logger::error("Failed to upload Google Drive credentials: " . $e->getMessage());
            wp_send_json_error('Upload failed: ' . $e->getMessage());
        }
    }

    public function handleAjaxTestPdf() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        try {
            $options = get_option('lda_settings', array());
            $pdf_handler = new LDA_PDFHandler($options);
            
            $engine = isset($_POST['engine']) ? sanitize_text_field($_POST['engine']) : null;
            $result = $pdf_handler->testPdfGeneration($engine);
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => 'PDF generation test successful!',
                    'engine' => $result['engine'] ?? 'unknown',
                    'stats' => $pdf_handler->getPdfStats()
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'PDF generation test failed: ' . $result['error'],
                    'stats' => $pdf_handler->getPdfStats()
                ));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'PDF test error: ' . $e->getMessage()
            ));
        }
    }
}